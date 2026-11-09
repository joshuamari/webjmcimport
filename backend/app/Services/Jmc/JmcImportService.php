<?php

namespace App\Services\Jmc;

use App\Services\Auth\KdtUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class JmcImportService
{
    public function __construct(
        private readonly ExcelDrawingListReader $reader,
        private readonly ProjectWriter $projects,
        private readonly ItemOfWorksWriter $items,
        private readonly DrawingReferenceWriter $drawings,
        private readonly JmcCatalog $catalog,
        private readonly JmcRowClassifier $classifier,
        private readonly ImportPreviewStore $previews,
        private readonly ImportHistoryStore $history,
        private readonly JmcPreviewQuery $previewQuery,
    ) {
    }

    /**
     * Legacy one-step import. Updates rows that already exist.
     * The review screen uses preview() and confirm(), which only insert new rows.
     *
     * @return array{completedProjects: list<string>, logs: list<string>}
     */
    public function import(string $filePath, string $group): array
    {
        $logs = ['IMPORTING JMC EXCEL...'];

        try {
            $projects = $this->reader->read($filePath);
            foreach ($this->reader->warnings() as $warning) {
                $logs[] = $warning;
            }
        } catch (RuntimeException $e) {
            $logs[] = $e->getMessage();

            throw $e;
        }

        if ($projects === []) {
            $msg = 'No JMR DRAWINGLIST found.';
            $logs[] = $msg;
            throw new RuntimeException($msg);
        }

        $completed = [];

        foreach ($projects as $projectData) {
            $projectName = $projectData['project'];
            $rows = $projectData['rows'];

            try {
                if (! $this->projects->write($projectName, $group, $rows)) {
                    $msg = "IMPORT Excel at PROJECT TABLE : Incomplete in project {$projectName}";
                    $logs[] = $msg;
                    throw new RuntimeException($msg);
                }

                if (! $this->items->write($projectName, $group, $rows)) {
                    $msg = "IMPORT Excel at ITEMOFWORKS TABLE : Incomplete in project {$projectName}";
                    $logs[] = $msg;
                    throw new RuntimeException($msg);
                }

                if (! $this->drawings->write($projectName, $group, $rows)) {
                    $msg = "IMPORT Excel at DRAWINGREFERENCE TABLE : Incomplete in project {$projectName}";
                    $logs[] = $msg;
                    throw new RuntimeException($msg);
                }

                $completed[] = $projectName;
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $logs[] = $e->getMessage();
                throw new RuntimeException($e->getMessage(), previous: $e);
            }
        }

        $projectList = implode("\n \t \t \t ", $completed);
        $logs[] = "IMPORT Excel : Completed in project \n \t \t \t {$projectList} ";

        return [
            'completedProjects' => $completed,
            'logs' => $logs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $filePath, string $originalName, string $group, KdtUser $user): array
    {
        $document = $this->document($filePath, $group);
        $token = $this->previews->create($filePath, [
            'employeeNumber' => $user->employeeNumber,
            'username' => $user->username,
            'group' => $group,
            'filename' => $this->safeName($originalName),
            'fileIssues' => $document['fileIssues'],
            'summary' => $document['summary'],
            'highlightedIssues' => $document['highlightedIssues'],
            'issueTotal' => $document['issueTotal'],
            'rows' => $document['rows'],
        ]);

        $stored = $this->previews->find($token);
        if ($stored === null) {
            throw new ImportFailedException('The upload could not be stored.');
        }

        return $this->previewQuery->page($stored, 'all', '', 1, 20);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewPage(string $token, KdtUser $user, string $filter, string $search, int $page, int $perPage): array
    {
        $this->previews->releaseAbandoned($token);
        $stored = $this->authorizedPreview($token, $user);

        return $this->previewQuery->page($stored, $filter, $search, $page, $perPage);
    }

    /**
     * @return array{filename: string, binary: string}
     */
    public function errorReport(string $token, KdtUser $user, JmcErrorReportExporter $exporter): array
    {
        $stored = $this->authorizedPreview($token, $user);

        return [
            'filename' => 'JMC-Import-Errors.xlsx',
            'binary' => $exporter->binary($stored['fileIssues'] ?? [], $stored['rows'] ?? []),
        ];
    }

    /**
     * Re-read the stored workbook and insert only rows that are still new.
     *
     * @return array{added: int, skipped: int, group: string, filename: string, historyId: string}
     */
    public function confirm(string $token, KdtUser $user): array
    {
        $outcome = $this->previews->exclusive($token, function (array $meta, callable $save) use ($user, $token) {
            $this->assertOwner($meta, $user);
            if (($meta['status'] ?? '') === 'committed') {
                $committed = is_array($meta['committed'] ?? null) ? $meta['committed'] : null;
                throw new ImportConflictException('This import was already confirmed.', $committed);
            }

            $meta['status'] = 'committing';
            $save($meta);

            try {
                $path = $this->previews->filePath($token);
                if ($path === null) {
                    throw new ImportRejectedException('This preview has expired. Upload the file again.');
                }

                $groupName = (string) $meta['group'];
                if (! $this->acquireLock($groupName)) {
                    throw new ImportFailedException('Another import is already running for this group. Wait a moment and try again.');
                }

                try {
                $classified = $this->classifyFile($path, $groupName);
                $document = $this->publish($classified);
                $meta = array_merge($meta, [
                    'fileIssues' => $document['fileIssues'],
                    'summary' => $document['summary'],
                    'highlightedIssues' => $document['highlightedIssues'],
                    'issueTotal' => $document['issueTotal'],
                    'rows' => $document['rows'],
                ]);

                if (($document['summary']['attention'] ?? 0) > 0) {
                    $meta['status'] = 'ready';
                    $save($meta);
                    throw new ImportRejectedException(
                        'This file still has rows that need attention. Review the updated results before importing.',
                        true,
                    );
                }

                $newRows = array_values(array_filter(
                    $classified['rows'],
                    fn (array $row) => ($row['result'] ?? '') === 'new',
                ));
                if ($newRows === []) {
                    $meta['status'] = 'ready';
                    $save($meta);
                    throw new ImportRejectedException('No new records found. All entries in this file already exist in Web JMR.');
                }

                $saved = $this->insertNew($groupName, $newRows);
                $meta['status'] = 'committed';
                $meta['summary']['new'] = 0;
                $meta['summary']['exists'] = (int) $document['summary']['exists'] + $saved['added'] + $saved['skippedNow'];
                $meta['committed'] = [
                    'added' => $saved['added'],
                    'skipped' => (int) $document['summary']['exists'] + $saved['skippedNow'],
                    'group' => (string) $meta['group'],
                    'filename' => (string) $meta['filename'],
                ];
                $save($meta);

                return [
                    'added' => $saved['added'],
                    'skipped' => (int) $document['summary']['exists'] + $saved['skippedNow'],
                    'group' => (string) $meta['group'],
                    'filename' => (string) $meta['filename'],
                    'projects' => $saved['projects'],
                ];
                } finally {
                    $this->releaseLock($groupName);
                }
            } catch (ImportRejectedException $e) {
                if (($meta['status'] ?? '') !== 'committed') {
                    $meta['status'] = 'ready';
                    $save($meta);
                }
                throw $e;
            } catch (Throwable $e) {
                if (($meta['status'] ?? '') !== 'committed') {
                    $meta['status'] = 'ready';
                    $save($meta);
                }
                throw $e;
            }
        });

        $historyId = '';
        try {
            $historyId = $this->history->append([
                'filename' => $outcome['filename'],
                'group' => $outcome['group'],
                'added' => $outcome['added'],
                'skipped' => $outcome['skipped'],
                'result' => 'Completed',
                'importedBy' => $user->username,
                'employeeNumber' => $user->employeeNumber,
                'projects' => $outcome['projects'],
                'note' => 'Existing JMC records were not changed.',
            ]);
        } catch (Throwable $e) {
            Log::error('JMC import history could not be stored', ['exception' => $e]);
        }

        return [
            'added' => $outcome['added'],
            'skipped' => $outcome['skipped'],
            'group' => $outcome['group'],
            'filename' => $outcome['filename'],
            'historyId' => $historyId,
        ];
    }

    /**
     * @param  list<string>  $groups
     * @return list<array<string, mixed>>
     */
    public function history(array $groups): array
    {
        return $this->history->listForGroups($groups);
    }

    /**
     * @return array<string, mixed>
     */
    public function historyDetail(string $id, KdtUser $user): array
    {
        $entry = $this->history->find($id);
        if ($entry === null || ! in_array((string) ($entry['group'] ?? ''), $user->groups, true)) {
            throw new ImportRejectedException('That import history record was not found.');
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizedPreview(string $token, KdtUser $user): array
    {
        $stored = $this->previews->find($token);
        if ($stored === null) {
            throw new ImportRejectedException('This preview has expired. Upload the file again.');
        }
        $this->assertOwner($stored, $user);

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function assertOwner(array $meta, KdtUser $user): void
    {
        $sameUser = (int) ($meta['employeeNumber'] ?? 0) === $user->employeeNumber;
        $group = (string) ($meta['group'] ?? '');
        if (! $sameUser || ! in_array($group, $user->groups, true)) {
            throw new ImportRejectedException('This preview has expired. Upload the file again.');
        }
    }

    /**
     * @return array{fileIssues: list<array<string, mixed>>, summary: array<string, int>, highlightedIssues: list<array<string, mixed>>, issueTotal: int, rows: list<array<string, mixed>>}
     */
    private function document(string $filePath, string $group): array
    {
        return $this->publish($this->classifyFile($filePath, $group));
    }

    /**
     * @return array{fileIssues: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    private function classifyFile(string $filePath, string $group): array
    {
        return $this->classifier->classify(
            $this->reader->inspect($filePath),
            $this->catalog->snapshot($group),
            $group,
        );
    }

    /**
     * @param  array{fileIssues: list<array<string, mixed>>, rows: list<array<string, mixed>>}  $classified
     * @return array{fileIssues: list<array<string, mixed>>, summary: array<string, int>, highlightedIssues: list<array<string, mixed>>, issueTotal: int, rows: list<array<string, mixed>>}
     */
    private function publish(array $classified): array
    {
        $rows = [];
        $new = 0;
        $exists = 0;
        $errors = 0;
        $highlighted = [];
        $issueTotal = count($classified['fileIssues']);

        foreach ($classified['fileIssues'] as $issue) {
            if (count($highlighted) < 30) {
                $highlighted[] = $issue;
            }
        }

        foreach ($classified['rows'] as $row) {
            $result = (string) ($row['result'] ?? 'error');
            if ($result === 'new') {
                $new++;
            } elseif ($result === 'exists') {
                $exists++;
            } else {
                $errors++;
                foreach ($row['issues'] as $issue) {
                    $issueTotal++;
                    if (count($highlighted) < 30) {
                        $highlighted[] = $issue;
                    }
                }
            }

            unset($row['values'], $row['columns']);
            $rows[] = $row;
        }

        return [
            'fileIssues' => $classified['fileIssues'],
            'summary' => [
                'dataRows' => count($rows),
                'new' => $new,
                'exists' => $exists,
                'errors' => $errors,
                'fileProblems' => count($classified['fileIssues']),
                'attention' => $errors + count($classified['fileIssues']),
            ],
            'highlightedIssues' => $highlighted,
            'issueTotal' => $issueTotal,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $newRows
     * @return array{added: int, skippedNow: int, projects: list<string>}
     */
    private function insertNew(string $group, array $newRows): array
    {
        $owned = false;
        try {
            $owned = $this->acquireLock($group);
            if (! $owned) {
                throw new ImportFailedException('Another import is already running for this group. Wait a moment and try again.');
            }

            return DB::connection('webjmr')->transaction(function () use ($group, $newRows) {
                $added = 0;
                $skippedNow = 0;
                $projects = [];
                $rechecked = $this->recheckBeforeInsert($group, $newRows);
                $byProject = [];
                foreach ($rechecked as $row) {
                    $byProject[$row['worksheet']][] = $row;
                }

                foreach ($byProject as $projectName => $rows) {
                    $values = array_map(fn (array $row) => $row['values'] ?? [], $rows);
                    $ensured = $this->projects->ensure((string) $projectName, $group, $values);
                    if ($ensured === null) {
                        throw new ImportRejectedException(
                            'This file still has rows that need attention. Review the updated results before importing.',
                            true,
                        );
                    }

                    $projectAdded = false;
                    foreach ($rows as $row) {
                        $rowValues = $row['values'] ?? [];
                        $itemName = trim((string) ($rowValues[ExcelHeaders::ITEM_OF_WORKS] ?? ''));
                        $itemId = $this->items->ensure($ensured['id'], $group, $itemName);
                        $inserted = $this->drawings->insertIfAbsent(
                            $ensured['id'],
                            $group,
                            (string) $projectName,
                            $itemId,
                            $rowValues,
                        );
                        if ($inserted) {
                            $added++;
                            $projectAdded = true;
                        } else {
                            $skippedNow++;
                        }
                    }

                    if ($projectAdded) {
                        $projects[] = (string) $projectName;
                    }
                }

                if ($added === 0) {
                    throw new ImportRejectedException('No new records found. All entries in this file already exist in Web JMR.');
                }

                return [
                    'added' => $added,
                    'skippedNow' => $skippedNow,
                    'projects' => $projects,
                ];
            });
        } catch (ImportRejectedException|ImportFailedException $e) {
            throw $e;
        } catch (QueryException $e) {
            Log::error('JMC import failed', ['exception' => $e]);
            throw new ImportFailedException('The import could not be saved. No JMC records were changed.');
        } catch (Throwable $e) {
            Log::error('JMC import failed', ['exception' => $e]);
            throw new ImportFailedException('The import could not be saved. No JMC records were changed.');
        } finally {
            if ($owned) {
                $this->releaseLock($group);
            }
        }
    }

    /**
     * Classify again after this group lock is held, so a concurrent import cannot
     * pass a conflict or insert a row that is no longer new.
     *
     * @param  list<array<string, mixed>>  $newRows
     * @return list<array<string, mixed>>
     */
    private function recheckBeforeInsert(string $group, array $newRows): array
    {
        $inspected = [];
        foreach ($newRows as $row) {
            $inspected[] = [
                'worksheet' => (string) ($row['worksheet'] ?? ''),
                'excelRow' => (int) ($row['excelRow'] ?? 0),
                'values' => is_array($row['values'] ?? null) ? $row['values'] : [],
                'issues' => [],
                'columns' => is_array($row['columns'] ?? null) ? $row['columns'] : [],
            ];
        }

        $again = $this->classifier->classify(
            ['fileIssues' => [], 'rows' => $inspected],
            $this->catalog->snapshot($group),
            $group,
        );

        $stillNew = [];
        foreach ($again['rows'] as $row) {
            if (($row['result'] ?? '') === 'error') {
                throw new ImportRejectedException(
                    'This file still has rows that need attention. Review the updated results before importing.',
                    true,
                );
            }
            if (($row['result'] ?? '') === 'new') {
                $stillNew[] = $row;
            }
        }

        return $stillNew;
    }

    private function acquireLock(string $group): bool
    {
        $row = DB::connection('webjmr')->select('SELECT GET_LOCK(?, 20) AS locked', [$this->lockName($group)]);

        return (int) ($row[0]->locked ?? 0) === 1;
    }

    private function releaseLock(string $group): void
    {
        DB::connection('webjmr')->select('SELECT RELEASE_LOCK(?)', [$this->lockName($group)]);
    }

    private function lockName(string $group): string
    {
        return 'jmcimp_'.substr(sha1($group), 0, 16);
    }

    private function safeName(string $name): string
    {
        $base = trim(basename(str_replace('\\', '/', $name)));
        if ($base === '' || $base === '.' || $base === '..') {
            return 'import.xlsx';
        }

        return mb_substr($base, 0, 180);
    }
}
