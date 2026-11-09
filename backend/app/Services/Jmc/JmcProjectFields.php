<?php

namespace App\Services\Jmc;

class JmcProjectFields
{
    /**
     * The only approved Budget in Charge alias. Every other value is compared as entered.
     */
    public static function normalizeBudget(string $value): string
    {
        $text = trim($value);

        return $text === 'CM' ? 'CEM' : $text;
    }

    /**
     * Order No. and Budget in Charge belong to the project. A filled value must be
     * recognized, or must already be the value stored for that project.
     * Job dates, manhours, and drawing details are not compared here.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $project
     * @param  array<string, true>|null  $allowedBudgets Null skips the recognized-value check.
     * @param  array<string, string>  $letters
     * @return list<array<string, mixed>>
     */
    public static function issues(
        string $worksheet,
        int $excelRow,
        array $values,
        ?array $project,
        ?array $allowedBudgets,
        array $letters,
    ): array {
        $issues = [];

        $order = trim((string) ($values[ExcelHeaders::ORDER_NUMBER] ?? ''));
        $storedOrder = trim((string) ($project['order'] ?? ''));
        if ($project !== null && $order !== '' && $storedOrder !== '' && $order !== $storedOrder) {
            $issues[] = ImportIssue::make(
                $worksheet,
                $excelRow,
                ExcelHeaders::ORDER_NUMBER,
                $letters[ExcelHeaders::ORDER_NUMBER] ?? 'A',
                'Order No. \''.$order.'\' differs from the existing value \''.$storedOrder.'\' for this project.',
                'Existing project values are not changed by import. Use the Order No. already stored for this project, or update the project in Web JMR.',
                'Order No. does not match the existing project',
            );
        }

        $budget = trim((string) ($values[ExcelHeaders::BUDGET_IN_CHARGE] ?? ''));
        if ($budget === '') {
            return $issues;
        }

        $normalized = self::normalizeBudget($budget);
        $storedBudget = trim((string) ($project['budget'] ?? ''));
        $storedNormalized = self::normalizeBudget($storedBudget);
        $matchesStored = $project !== null && $storedNormalized !== '' && $normalized === $storedNormalized;
        $recognized = $allowedBudgets === null || isset($allowedBudgets[$normalized]);

        if (! $matchesStored && ! $recognized) {
            $letter = $letters[ExcelHeaders::BUDGET_IN_CHARGE] ?? 'B';
            $issues[] = ImportIssue::make(
                $worksheet,
                $excelRow,
                ExcelHeaders::BUDGET_IN_CHARGE,
                $letter,
                'Excel row '.$excelRow.', Budget in Charge ('.$letter.'): \''.$budget.'\' is not a recognized value.',
                'Use a Budget in Charge from the Web JMR list. CM is accepted and saved as CEM.',
                'Budget in Charge is not recognized',
            );

            return $issues;
        }

        if ($project !== null && $storedNormalized !== '' && $normalized !== $storedNormalized) {
            $issues[] = ImportIssue::make(
                $worksheet,
                $excelRow,
                ExcelHeaders::BUDGET_IN_CHARGE,
                $letters[ExcelHeaders::BUDGET_IN_CHARGE] ?? 'B',
                'Budget in Charge \''.$budget.'\' differs from the existing value \''.$storedBudget.'\' for this project.',
                'Existing project values are not changed by import. Use the Budget in Charge already stored for this project, or update the project in Web JMR.',
                'Budget in Charge does not match the existing project',
            );
        }

        return $issues;
    }
}
