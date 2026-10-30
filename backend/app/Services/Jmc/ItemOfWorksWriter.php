<?php

namespace App\Services\Jmc;

use Illuminate\Support\Facades\DB;

class ItemOfWorksWriter
{
    private const TABLE = 'itemofworkstable';

    public function __construct(private readonly ProjectWriter $projects)
    {
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    public function write(string $projectName, string $group, array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        $project = $this->projects->findProject($group, $projectName);
        if ($project === null) {
            return false;
        }

        $projectId = (string) $project->fldID;
        $seen = [];

        foreach ($rows as $row) {
            $itemOfWork = trim($row[ExcelHeaders::ITEM_OF_WORKS] ?? '');
            if ($itemOfWork === '') {
                continue;
            }

            $key = mb_strtolower($itemOfWork);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $existing = DB::connection('webjmr')->table(self::TABLE)
                ->where('fldProject', $projectId)
                ->where('fldGroup', $group)
                ->where('fldItem', $itemOfWork)
                ->where('fldDelete', 0)
                ->first();

            if ($existing !== null) {
                DB::connection('webjmr')->table(self::TABLE)
                    ->where('fldID', $existing->fldID)
                    ->update([
                        'fldProject' => $projectId,
                        'fldGroup' => $group,
                        'fldItem' => $itemOfWork,
                    ]);

                continue;
            }

            // Case-insensitive match (desktop uses OrdinalIgnoreCase on update path)
            $existingInsensitive = DB::connection('webjmr')->table(self::TABLE)
                ->where('fldProject', $projectId)
                ->where('fldGroup', $group)
                ->where('fldDelete', 0)
                ->whereRaw('LOWER(fldItem) = ?', [mb_strtolower($itemOfWork)])
                ->first();

            if ($existingInsensitive !== null) {
                DB::connection('webjmr')->table(self::TABLE)
                    ->where('fldID', $existingInsensitive->fldID)
                    ->update([
                        'fldProject' => $projectId,
                        'fldGroup' => $group,
                        'fldItem' => $itemOfWork,
                    ]);

                continue;
            }

            $maxPrio = DB::connection('webjmr')->table(self::TABLE)
                ->where('fldProject', $projectId)
                ->max('fldPriority');
            $prio = $maxPrio === null ? 1 : ((int) $maxPrio) + 1;

            DB::connection('webjmr')->table(self::TABLE)->insert([
                'fldProject' => $projectId,
                'fldGroup' => $group,
                'fldItem' => $itemOfWork,
                'fldPriority' => $prio,
            ]);
        }

        return true;
    }

    /**
     * @return array<string, string> map lowercase item name => item fldID
     */
    public function itemIdMap(string $projectId): array
    {
        $rows = DB::connection('webjmr')->table(self::TABLE)
            ->where('fldProject', $projectId)
            ->where('fldDelete', 0)
            ->get(['fldID', 'fldItem']);

        $map = [];
        foreach ($rows as $row) {
            $map[mb_strtolower((string) $row->fldItem)] = (string) $row->fldID;
        }

        return $map;
    }
}
