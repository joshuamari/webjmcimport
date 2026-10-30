<?php

namespace App\Services\Jmc;

use Illuminate\Support\Facades\DB;

class DrawingReferenceWriter
{
    private const TABLE = 'drawingreference';

    public function __construct(
        private readonly ProjectWriter $projects,
        private readonly ItemOfWorksWriter $items,
    ) {
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
        $itemMap = $this->items->itemIdMap($projectId);
        if ($itemMap === []) {
            return false;
        }

        $isDefault = ExcelHeaders::isProjectDefault($projectName);

        foreach ($rows as $row) {
            $itemName = trim($row[ExcelHeaders::ITEM_OF_WORKS] ?? '');
            $jobDescription = trim($row[ExcelHeaders::DESCRIPTION] ?? '');
            $itemOfWorkId = $itemMap[mb_strtolower($itemName)] ?? null;

            if ($itemOfWorkId === null && ! $isDefault) {
                continue;
            }

            // Empty Excel cells must be NULL for int/date columns (strict MySQL),
            // matching how loose desktop inserts effectively coerced blanks.
            $payload = [
                'fldNoSheets' => DbValue::nullableInt($row[ExcelHeaders::NUMBER_OF_SHEET] ?? null),
                'fldPaperSize' => DbValue::nullableString($row[ExcelHeaders::PAPER_SIZE] ?? null),
                'fldDrawingName' => DbValue::nullableString($row[ExcelHeaders::DOCUMENT_NAME] ?? null),
                'fldJob' => $jobDescription,
                'fldKHIDate' => DbValue::nullableDate($row[ExcelHeaders::KHI_REQUESTED_DATE] ?? null),
                'fldKHIC' => DbValue::nullableString($row[ExcelHeaders::KHI_IN_CHARGE] ?? null),
                'fldKHIDeadline' => DbValue::nullableDate($row[ExcelHeaders::KHI_DEADLINE] ?? null),
                'fldKDTDeadline' => DbValue::nullableDate($row[ExcelHeaders::KDT_DEADLINE] ?? null),
                'fldExpectedMH' => DbValue::nullableInt($row[ExcelHeaders::EXPECTED_MANHOUR] ?? null) ?? 0,
            ];

            if (array_key_exists(ExcelHeaders::KHI_PREP, $row)) {
                $payload['fldKHIPrep'] = DbValue::nullableInt($row[ExcelHeaders::KHI_PREP] ?? null) ?? 0;
            }

            $query = DB::connection('webjmr')->table(self::TABLE)
                ->where('fldGroup', $group)
                ->where('fldProject', $projectId)
                ->where('fldJob', $jobDescription)
                ->where('fldDelete', 0);

            if ($isDefault) {
                $query->whereNull('fldItem');
            } else {
                $query->where('fldItem', $itemOfWorkId);
            }

            $existing = $query->first();

            if ($existing !== null) {
                $update = array_merge($payload, [
                    'fldProject' => $projectId,
                    'fldGroup' => $group,
                    'fldItem' => $isDefault ? null : $itemOfWorkId,
                ]);
                DB::connection('webjmr')->table(self::TABLE)
                    ->where('fldID', $existing->fldID)
                    ->update($update);

                continue;
            }

            $prioQuery = DB::connection('webjmr')->table(self::TABLE);
            if ($isDefault) {
                $prioQuery->whereNull('fldItem');
            } else {
                $prioQuery->where('fldItem', $itemOfWorkId);
            }
            $maxPrio = $prioQuery->max('fldPriority');
            $prio = $maxPrio === null ? 1 : ((int) $maxPrio) + 1;

            DB::connection('webjmr')->table(self::TABLE)->insert(array_merge($payload, [
                'fldProject' => $projectId,
                'fldGroup' => $group,
                'fldItem' => $isDefault ? null : $itemOfWorkId,
                'fldPriority' => $prio,
            ]));
        }

        return true;
    }
}
