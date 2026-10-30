<?php

namespace App\Services\Jmc;

use Illuminate\Support\Facades\DB;

class ProjectWriter
{
    private const TABLE = 'projectstable';

    /**
     * @param  list<array<string, string>>  $rows
     */
    public function write(string $project, string $group, array $rows): bool
    {
        if (ExcelHeaders::isProjectDefault($project)) {
            return true;
        }

        $orderNumber = '';
        $buInCharge = '';
        foreach ($rows as $row) {
            $order = trim($row[ExcelHeaders::ORDER_NUMBER] ?? '');
            $buic = trim($row[ExcelHeaders::BUDGET_IN_CHARGE] ?? '');
            if ($order !== '' || $buic !== '') {
                $orderNumber = $order;
                $buInCharge = $buic === 'CM' ? 'CEM' : $buic;
                break;
            }
        }

        $existing = $this->findProject($group, $project);

        if ($existing !== null) {
            DB::connection('webjmr')->table(self::TABLE)
                ->where('fldID', $existing->fldID)
                ->update([
                    'fldProject' => $project,
                    'fldGroup' => $group,
                    'fldBUIC' => $buInCharge,
                    'fldOrder' => $orderNumber,
                ]);

            return true;
        }

        $maxPrio = DB::connection('webjmr')->table(self::TABLE)
            ->where('fldGroup', $group)
            ->max('fldPriority');
        $prio = $maxPrio === null ? 1 : ((int) $maxPrio) + 1;

        DB::connection('webjmr')->table(self::TABLE)->insert([
            'fldProject' => $project,
            'fldGroup' => $group,
            'fldBUIC' => $buInCharge,
            'fldOrder' => $orderNumber,
            'fldPriority' => $prio,
        ]);

        return true;
    }

    public function findProject(string $group, string $project): ?object
    {
        $query = DB::connection('webjmr')->table(self::TABLE)
            ->where('fldProject', $project)
            ->where('fldDelete', 0);

        if (ExcelHeaders::isProjectDefault($project)) {
            $query->whereNull('fldGroup');
        } else {
            $query->where('fldGroup', $group);
        }

        return $query->first();
    }
}
