<?php

namespace App\Services\Jmc;

class ImportIssue
{
    /**
     * @return array{worksheet: string, excelRow: int, column: string, columnLetter: string, problem: string, correction: string, summary: string}
     */
    public static function make(
        string $worksheet,
        int $excelRow,
        string $column,
        string $columnLetter,
        string $problem,
        string $correction,
        string $summary,
    ): array {
        return [
            'worksheet' => $worksheet,
            'excelRow' => $excelRow,
            'column' => $column,
            'columnLetter' => $columnLetter,
            'problem' => $problem,
            'correction' => $correction,
            'summary' => $summary,
        ];
    }
}
