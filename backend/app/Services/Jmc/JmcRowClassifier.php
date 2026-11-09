<?php

namespace App\Services\Jmc;

class JmcRowClassifier
{
    /**
     * Compare inspected Excel rows with JMC records.
     * A job request already stored under the same project and item of works is skipped
     * when its project Order No. and Budget in Charge do not conflict.
     * The same description under a different project is a different record.
     *
     * @param  array{fileIssues: list<array<string, mixed>>, rows: list<array<string, mixed>>}  $inspected
     * @param  array{
     *     projects: array<string, array{id: string, name: string}>,
     *     items: array<string, array{id: string, name: string}>,
     *     jobs: array<string, true>,
     *     drawings: array<string, list<string>>
     * }  $snapshot
     * @return array{fileIssues: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function classify(array $inspected, array $snapshot, string $group): array
    {
        $prepared = [];
        foreach ($inspected['rows'] as $row) {
            $values = $row['values'] ?? [];
            $project = (string) $row['worksheet'];
            $item = trim((string) ($values[ExcelHeaders::ITEM_OF_WORKS] ?? ''));
            $job = trim((string) ($values[ExcelHeaders::DESCRIPTION] ?? ''));
            $drawing = trim((string) ($values[ExcelHeaders::DOCUMENT_NAME] ?? ''));
            $isDefault = ExcelHeaders::isProjectDefault($project);
            $identity = $isDefault
                ? 'd|'.mb_strtolower($project).'|'.mb_strtolower($job)
                : 'n|'.mb_strtolower($project).'|'.mb_strtolower($item).'|'.mb_strtolower($job);
            $drawingKey = $drawing === ''
                ? ''
                : ($isDefault
                    ? 'd|'.mb_strtolower($project).'|'.mb_strtolower($drawing)
                    : 'n|'.mb_strtolower($project).'|'.mb_strtolower($item).'|'.mb_strtolower($drawing));

            $prepared[] = [
                'source' => $row,
                'project' => $project,
                'item' => $item,
                'job' => $job,
                'drawing' => $drawing,
                'isDefault' => $isDefault,
                'identity' => $identity,
                'drawingKey' => $drawingKey,
                'canMatch' => $item !== '' && $job !== '',
                'db' => $this->lookup($snapshot, $group, $project, $item, $job, $drawing, $isDefault, $item !== '' && $job !== ''),
            ];
        }

        $firstIdentity = [];
        $drawingsInFile = [];
        foreach ($prepared as $index => $row) {
            if (! $row['canMatch'] || $row['db'] === 'exists' || $row['db'] === 'missing-project') {
                continue;
            }
            if (! isset($firstIdentity[$row['identity']])) {
                $firstIdentity[$row['identity']] = $index;
            }
            if ($row['drawingKey'] !== '') {
                $drawingsInFile[$row['drawingKey']][$row['job']] = true;
            }
        }

        $rows = [];
        foreach ($prepared as $index => $row) {
            $issues = $row['source']['issues'] ?? [];
            $projectIssues = $this->projectIssues($row, $snapshot);
            $issues = array_merge($issues, $projectIssues);
            $db = $row['db'];

            if ($row['canMatch'] && $db === 'exists' && $projectIssues === []) {
                $rows[] = $this->present($row, 'exists', 'Already in Web JMR and will be skipped.', []);
                continue;
            }

            if ($row['canMatch'] && $db === 'missing-project') {
                $issues[] = ImportIssue::make(
                    $row['project'],
                    (int) $row['source']['excelRow'],
                    'Worksheet',
                    '',
                    'This built-in project does not exist in Web JMR, so new rows cannot be attached to it.',
                    'Use a project worksheet that your group can add, or pick a built-in project that already exists.',
                    'Built-in project was not found',
                );
            }

            if ($row['canMatch'] && $db === 'ambiguous') {
                $issues[] = ImportIssue::make(
                    $row['project'],
                    (int) $row['source']['excelRow'],
                    ExcelHeaders::DOCUMENT_NAME,
                    '',
                    'This drawing name already belongs to a different Job Request Description under the same item of works.',
                    'If this is a new request, use a different drawing name. If you are changing an existing request, update it in Web JMR instead of importing it.',
                    'Drawing name matches a different job request',
                );
            }

            if ($row['canMatch'] && $db !== 'exists' && isset($firstIdentity[$row['identity']]) && $firstIdentity[$row['identity']] !== $index) {
                $firstRow = (int) $prepared[$firstIdentity[$row['identity']]]['source']['excelRow'];
                $issues[] = ImportIssue::make(
                    $row['project'],
                    (int) $row['source']['excelRow'],
                    ExcelHeaders::DESCRIPTION,
                    '',
                    'This Job Request Description is already listed for the same item of works in row '.$firstRow.'.',
                    'Remove the repeated row from the Excel file and upload it again.',
                    'Repeated in this file',
                );
            }

            if (
                $row['canMatch']
                && $db !== 'exists'
                && $row['drawingKey'] !== ''
                && count($drawingsInFile[$row['drawingKey']] ?? []) > 1
            ) {
                $issues[] = ImportIssue::make(
                    $row['project'],
                    (int) $row['source']['excelRow'],
                    ExcelHeaders::DOCUMENT_NAME,
                    '',
                    'This drawing name is used by more than one Job Request Description in this file.',
                    'Keep one job request for this drawing name, or give the new request its own drawing name.',
                    'Drawing name is repeated with a different description',
                );
            }

            if ($issues !== []) {
                $rows[] = $this->present($row, 'error', $this->details($issues), $issues);
                continue;
            }

            $rows[] = $this->present($row, 'new', 'Will be added to Web JMR.', []);
        }

        return [
            'fileIssues' => $inspected['fileIssues'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function lookup(
        array $snapshot,
        string $group,
        string $project,
        string $item,
        string $job,
        string $drawing,
        bool $isDefault,
        bool $canMatch,
    ): string {
        if (! $canMatch) {
            return 'invalid';
        }

        $projectKey = ($isDefault ? 'default' : 'group').'|'.mb_strtolower($project);
        $projectRow = $snapshot['projects'][$projectKey] ?? null;
        if ($projectRow === null) {
            return $isDefault ? 'missing-project' : 'new';
        }

        $projectId = $projectRow['id'];
        $itemKey = '';
        if (! $isDefault) {
            $itemRow = $snapshot['items'][$projectId.'|'.mb_strtolower($group).'|'.mb_strtolower($item)] ?? null;
            if ($itemRow === null) {
                return 'new';
            }
            $itemKey = $itemRow['id'];
        }

        $jobKey = $projectId.'|'.$itemKey.'|'.mb_strtolower($group).'|'.mb_strtolower($job);
        if (isset($snapshot['jobs'][$jobKey])) {
            return 'exists';
        }

        if ($drawing !== '') {
            $drawingKey = $projectId.'|'.$itemKey.'|'.mb_strtolower($group).'|'.mb_strtolower($drawing);
            $owners = $snapshot['drawings'][$drawingKey] ?? [];
            foreach ($owners as $owner) {
                if ($owner !== mb_strtolower($job)) {
                    return 'ambiguous';
                }
            }
        }

        return 'new';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    private function present(array $row, string $result, string $details, array $issues): array
    {
        $values = $row['source']['values'] ?? [];

        return [
            'worksheet' => $row['project'],
            'excelRow' => (int) $row['source']['excelRow'],
            'project' => $row['project'],
            'itemOfWorks' => $row['item'],
            'jobRequest' => $row['job'],
            'expectedMh' => trim((string) ($values[ExcelHeaders::EXPECTED_MANHOUR] ?? '')),
            'result' => $result,
            'details' => $details,
            'issues' => $issues,
            'values' => $values,
            'columns' => is_array($row['source']['columns'] ?? null) ? $row['source']['columns'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function projectIssues(array $row, array $snapshot): array
    {
        $projectKey = ($row['isDefault'] ? 'default' : 'group').'|'.mb_strtolower((string) $row['project']);
        $project = $snapshot['projects'][$projectKey] ?? null;
        $allowed = $snapshot['budgets'] ?? null;

        return JmcProjectFields::issues(
            (string) $row['project'],
            (int) $row['source']['excelRow'],
            is_array($row['source']['values'] ?? null) ? $row['source']['values'] : [],
            is_array($project) ? $project : null,
            is_array($allowed) ? $allowed : null,
            [
                ExcelHeaders::ORDER_NUMBER => $this->letter($row, ExcelHeaders::ORDER_NUMBER, 'A'),
                ExcelHeaders::BUDGET_IN_CHARGE => $this->letter($row, ExcelHeaders::BUDGET_IN_CHARGE, 'B'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function letter(array $row, string $header, string $fallback): string
    {
        $columns = $row['source']['columns'] ?? [];
        $letter = is_array($columns) ? trim((string) ($columns[$header] ?? '')) : '';

        return $letter !== '' ? $letter : $fallback;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function details(array $issues): string
    {
        $first = (string) ($issues[0]['problem'] ?? 'This row needs attention.');
        $extra = count($issues) - 1;
        if ($extra > 0) {
            return $first.' +'.$extra.' more';
        }

        return $first;
    }
}
