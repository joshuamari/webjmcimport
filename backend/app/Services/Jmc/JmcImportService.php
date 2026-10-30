<?php

namespace App\Services\Jmc;

use RuntimeException;
use Throwable;

class JmcImportService
{
    public function __construct(
        private readonly ExcelDrawingListReader $reader,
        private readonly ProjectWriter $projects,
        private readonly ItemOfWorksWriter $items,
        private readonly DrawingReferenceWriter $drawings,
    ) {
    }

    /**
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
}
