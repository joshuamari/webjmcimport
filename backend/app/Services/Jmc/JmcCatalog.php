<?php

namespace App\Services\Jmc;

use Illuminate\Support\Facades\DB;

class JmcCatalog
{
    /**
     * Active and inactive rows both count as existing. Deleted rows do not.
     * Daily Report only offers rows with fldActive = 1, which is the column default for inserts.
     *
     * @return array{
     *     projects: array<string, array{id: string, name: string, order: string, budget: string}>,
     *     items: array<string, array{id: string, name: string}>,
     *     jobs: array<string, true>,
     *     drawings: array<string, list<string>>,
     *     budgets: array<string, true>
     * }
     */
    public function snapshot(string $group): array
    {
        $projects = DB::connection('webjmr')->table('projectstable')
            ->where('fldDelete', 0)
            ->where(function ($query) use ($group) {
                $query->where('fldGroup', $group)->orWhereNull('fldGroup');
            })
            ->get(['fldID', 'fldProject', 'fldGroup', 'fldOrder', 'fldBUIC']);

        $projectMap = [];
        $projectIds = [];
        foreach ($projects as $project) {
            $id = (string) $project->fldID;
            $projectIds[] = $id;
            $scope = $project->fldGroup === null || $project->fldGroup === ''
                ? 'default'
                : 'group';
            if ($scope === 'group' && (string) $project->fldGroup !== $group) {
                continue;
            }
            $key = $scope.'|'.mb_strtolower(trim((string) $project->fldProject));
            $projectMap[$key] = [
                'id' => $id,
                'name' => trim((string) $project->fldProject),
                'order' => trim((string) ($project->fldOrder ?? '')),
                'budget' => trim((string) ($project->fldBUIC ?? '')),
            ];
        }

        $items = [];
        $jobs = [];
        $drawings = [];
        if ($projectIds === []) {
            return [
                'projects' => $projectMap,
                'items' => $items,
                'jobs' => $jobs,
                'drawings' => $drawings,
                'budgets' => $this->allowedBudgets(),
            ];
        }

        $itemRows = DB::connection('webjmr')->table('itemofworkstable')
            ->where('fldDelete', 0)
            ->where('fldGroup', $group)
            ->whereIn('fldProject', $projectIds)
            ->get(['fldID', 'fldProject', 'fldItem']);

        foreach ($itemRows as $item) {
            $key = ((string) $item->fldProject).'|'.mb_strtolower($group).'|'.mb_strtolower(trim((string) $item->fldItem));
            $items[$key] = [
                'id' => (string) $item->fldID,
                'name' => trim((string) $item->fldItem),
            ];
        }

        $jobRows = DB::connection('webjmr')->table('drawingreference')
            ->where('fldDelete', 0)
            ->where('fldGroup', $group)
            ->whereIn('fldProject', $projectIds)
            ->get(['fldProject', 'fldItem', 'fldJob', 'fldDrawingName']);

        foreach ($jobRows as $job) {
            $itemKey = $job->fldItem === null || $job->fldItem === '' ? '' : (string) $job->fldItem;
            $jobText = mb_strtolower(trim((string) $job->fldJob));
            $jobs[((string) $job->fldProject).'|'.$itemKey.'|'.mb_strtolower($group).'|'.$jobText] = true;

            $drawing = mb_strtolower(trim((string) $job->fldDrawingName));
            if ($drawing === '') {
                continue;
            }
            $drawingKey = ((string) $job->fldProject).'|'.$itemKey.'|'.mb_strtolower($group).'|'.$drawing;
            $drawings[$drawingKey] ??= [];
            if (! in_array($jobText, $drawings[$drawingKey], true)) {
                $drawings[$drawingKey][] = $jobText;
            }
        }

        return [
            'projects' => $projectMap,
            'items' => $items,
            'jobs' => $jobs,
            'drawings' => $drawings,
            'budgets' => $this->allowedBudgets(),
        ];
    }

    /**
     * Budget in Charge options used by the Web JMR project form.
     *
     * @return array<string, true>
     */
    private function allowedBudgets(): array
    {
        $values = DB::connection('kdtph')->table('kdtbu')
            ->where('fldDepartment', '<>', '')
            ->orderBy('fldBU')
            ->pluck('fldBU');

        $allowed = [];
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $allowed[$text] = true;
            }
        }

        return $allowed;
    }
}
