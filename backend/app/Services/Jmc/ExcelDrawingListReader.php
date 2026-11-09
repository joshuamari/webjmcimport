<?php

namespace App\Services\Jmc;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class ExcelDrawingListReader
{
    private const MAX_SHEET_ROWS = 5000;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @return list<array{project: string, rows: list<array<string, string>>}>
     */
    public function read(string $filePath): array
    {
        $this->warnings = [];

        if (! is_file($filePath)) {
            throw new RuntimeException('Excel file not found.');
        }

        $spreadsheet = IOFactory::load($filePath);
        $projects = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $projectName = trim((string) $sheet->getTitle());
            if ($projectName === '' || ExcelHeaders::isIgnoredSheet($projectName)) {
                continue;
            }

            $matrix = $sheet->toArray(null, true, true, false);
            if (count($matrix) < 2) {
                continue;
            }

            foreach ($matrix as $rowIndex => $row) {
                foreach ($row as $colIndex => $cell) {
                    $cellVal = $this->stringify($cell);
                    if (ExcelHeaders::hasSpecialCharacter($cellVal)) {
                        $letter = Coordinate::stringFromColumnIndex(((int) $colIndex) + 1);
                        throw new RuntimeException(
                            "EXCEL {$projectName} : Cell {$letter}".(((int) $rowIndex) + 1).' has special character.'
                        );
                    }
                }
            }

            $rawHeaders = array_map(fn ($v) => trim($this->stringify($v)), $matrix[0]);
            if (! ExcelHeaders::isValidHeaderRow($rawHeaders)) {
                continue;
            }

            $hasKhiPrep = in_array(ExcelHeaders::KHI_PREP, $rawHeaders, true);
            $allowed = ExcelHeaders::baseList();
            if ($hasKhiPrep) {
                $allowed[] = ExcelHeaders::KHI_PREP;
            }

            $headerIndex = [];
            foreach ($rawHeaders as $i => $name) {
                if (in_array($name, $allowed, true) && ! array_key_exists($name, $headerIndex)) {
                    $headerIndex[$name] = $i;
                }
            }

            $rows = [];
            for ($r = 1; $r < count($matrix); $r++) {
                $source = $matrix[$r];
                $mapped = [];
                foreach ($allowed as $header) {
                    $idx = $headerIndex[$header] ?? null;
                    $mapped[$header] = $idx === null ? '' : $this->stringify($source[$idx] ?? '');
                }

                if (trim($mapped[ExcelHeaders::ITEM_OF_WORKS]) === '') {
                    continue;
                }

                $rows[] = $mapped;
            }

            if ($rows === []) {
                continue;
            }

            $codes = [];
            foreach ($rows as $row) {
                $order = trim($row[ExcelHeaders::ORDER_NUMBER]);
                $buic = trim($row[ExcelHeaders::BUDGET_IN_CHARGE]);
                if ($order !== '' && $buic !== '') {
                    $codes[$order.$buic] = true;
                }
            }
            if (count($codes) > 1) {
                $this->warnings[] = "EXCEL {$projectName} : Invalid Multiple item in column BU IN CHARGE and/or ORDER NUMBER";
                continue;
            }

            $projects[] = [
                'project' => $projectName,
                'rows' => $rows,
            ];
        }

        return $projects;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Read a workbook for preview. Blank rows are omitted. excelRow is the worksheet row.
     *
     * @return array{fileIssues: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function inspect(string $filePath): array
    {
        if (! is_file($filePath)) {
            return [
                'fileIssues' => [$this->fileIssue('', 'The uploaded file could not be read.', 'Choose the Excel file again.')],
                'rows' => [],
            ];
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (Throwable) {
            return [
                'fileIssues' => [$this->fileIssue('', 'This file could not be read as an Excel workbook.', 'Save it as an .xlsx file and upload it again.')],
                'rows' => [],
            ];
        }

        $fileIssues = [];
        $rows = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $projectName = trim((string) $sheet->getTitle());
            if ($projectName === '' || ExcelHeaders::isIgnoredSheet($projectName)) {
                continue;
            }

            $matrix = $sheet->toArray(null, true, true, false);
            if (count($matrix) < 2) {
                continue;
            }

            $rawHeaders = array_map(fn ($v) => trim($this->stringify($v)), $matrix[0]);
            $missing = ExcelHeaders::missingHeaders($rawHeaders);
            if ($missing !== []) {
                $missingText = count($missing) === 1
                    ? "The Excel file is missing the '{$missing[0]}' column. Please use the provided template or correct your file."
                    : 'The Excel file is missing these columns: '.implode(', ', $missing).'. Please use the provided template or correct your file.';
                $fileIssues[] = ImportIssue::make(
                    $projectName,
                    1,
                    'Header row',
                    'A',
                    $missingText,
                    'Download the Excel template and use its header row on this worksheet, then upload the file again.',
                    'Missing columns on '.$projectName,
                );
                continue;
            }

            $hasKhiPrep = in_array(ExcelHeaders::KHI_PREP, $rawHeaders, true);
            $allowed = ExcelHeaders::baseList();
            if ($hasKhiPrep) {
                $allowed[] = ExcelHeaders::KHI_PREP;
            }

            $headerIndex = [];
            foreach ($rawHeaders as $i => $name) {
                if (in_array($name, $allowed, true) && ! array_key_exists($name, $headerIndex)) {
                    $headerIndex[$name] = $i;
                }
            }

            $dataRows = count($matrix) - 1;
            if ($dataRows > self::MAX_SHEET_ROWS) {
                $fileIssues[] = ImportIssue::make(
                    $projectName,
                    0,
                    'Worksheet',
                    '',
                    'This worksheet has too many rows to preview at once.',
                    'Split the worksheet and upload it again.',
                    $projectName.' has too many rows',
                );
                continue;
            }

            $sheetRows = [];
            $codes = [];
            $sheetIssues = $this->sheetNameIssues($projectName);

            for ($r = 1; $r < count($matrix); $r++) {
                $excelRow = $r + 1;
                $source = $matrix[$r];
                if ($this->rowIsBlank($source)) {
                    continue;
                }

                $issues = $sheetIssues;
                foreach ($source as $colIndex => $cell) {
                    $cellVal = $this->stringify($cell);
                    if ($cellVal !== '' && ExcelHeaders::hasSpecialCharacter($cellVal)) {
                        $letter = Coordinate::stringFromColumnIndex(((int) $colIndex) + 1);
                        $issues[] = ImportIssue::make(
                            $projectName,
                            $excelRow,
                            'Column '.$letter,
                            $letter,
                            'This cell contains a character that cannot be imported.',
                            'Remove characters such as \\ | ! $ % = * ? @ \' < > from row '.$excelRow.' and upload the file again.',
                            'Row '.$excelRow.' has a special character',
                        );
                    }
                }

                $mapped = [];
                $columns = [];
                foreach ($allowed as $header) {
                    $idx = $headerIndex[$header] ?? null;
                    $letter = $idx === null ? '' : Coordinate::stringFromColumnIndex($idx + 1);
                    $read = $idx === null
                        ? ['value' => '', 'issues' => []]
                        : $this->readMappedCell($sheet, $idx, $excelRow, $header, $projectName, $letter, $this->stringify($source[$idx] ?? ''));
                    $mapped[$header] = $read['value'];
                    $columns[$header] = $letter;
                    foreach ($read['issues'] as $issue) {
                        $issues[] = $issue;
                    }
                }

                $item = trim($mapped[ExcelHeaders::ITEM_OF_WORKS] ?? '');
                $job = trim($mapped[ExcelHeaders::DESCRIPTION] ?? '');
                if ($item === '') {
                    $letter = Coordinate::stringFromColumnIndex(($headerIndex[ExcelHeaders::ITEM_OF_WORKS] ?? 0) + 1);
                    $issues[] = ImportIssue::make(
                        $projectName,
                        $excelRow,
                        ExcelHeaders::ITEM_OF_WORKS,
                        $letter,
                        'Item of Works is required.',
                        'Open your Excel file, enter the item of works in row '.$excelRow.', and upload the corrected file again.',
                        'Row '.$excelRow.' is missing Item of Works',
                    );
                }
                if ($job === '') {
                    $letter = Coordinate::stringFromColumnIndex(($headerIndex[ExcelHeaders::DESCRIPTION] ?? 0) + 1);
                    $issues[] = ImportIssue::make(
                        $projectName,
                        $excelRow,
                        ExcelHeaders::DESCRIPTION,
                        $letter,
                        'Job Request Description is required.',
                        'Open your Excel file, enter the job request description in row '.$excelRow.', and upload the corrected file again.',
                        'Row '.$excelRow.' is missing Job Request Description',
                    );
                }

                $order = trim($mapped[ExcelHeaders::ORDER_NUMBER] ?? '');
                $buic = trim($mapped[ExcelHeaders::BUDGET_IN_CHARGE] ?? '');
                if ($order !== '' && $buic !== '') {
                    $codes[$order.$buic] = true;
                }

                $sheetRows[] = [
                    'worksheet' => $projectName,
                    'excelRow' => $excelRow,
                    'values' => $mapped,
                    'issues' => $issues,
                    'columns' => $columns,
                ];
            }

            if ($sheetRows === []) {
                continue;
            }

            if (count($codes) > 1) {
                $this->appendIdentityMismatches($sheetRows, $projectName, $headerIndex);
            }

            foreach ($sheetRows as $sheetRow) {
                $rows[] = $sheetRow;
            }
        }

        if ($rows === [] && $fileIssues === []) {
            $fileIssues[] = $this->fileIssue(
                '',
                'No importable rows were found.',
                'Add a worksheet named after the project, with the template headers and at least one data row. The Instructions and Example sheets are not imported.',
            );
        }

        return [
            'fileIssues' => $fileIssues,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sheetNameIssues(string $projectName): array
    {
        $issues = [];
        if (mb_strlen($projectName) > 100) {
            $issues[] = ImportIssue::make(
                $projectName,
                0,
                'Worksheet',
                '',
                'The worksheet name is the project, and it is longer than 100 characters.',
                'Rename the worksheet to 100 characters or fewer.',
                'Project name is too long',
            );
        }
        if (ExcelHeaders::hasSpecialCharacter($projectName)) {
            $issues[] = ImportIssue::make(
                $projectName,
                0,
                'Worksheet',
                '',
                'The worksheet name contains a character that cannot be imported.',
                'Rename the worksheet and remove special characters, then upload the file again.',
                'Project name has a special character',
            );
        }

        return $issues;
    }

    /**
     * A project sheet can have one Order No. and one Budget in Charge.
     * Compare each field with the first valid pair, and record only the cells that differ.
     *
     * @param  list<array<string, mixed>>  $sheetRows
     * @param  array<string, int>  $headerIndex
     */
    private function appendIdentityMismatches(array &$sheetRows, string $projectName, array $headerIndex): void
    {
        $orderHeader = ExcelHeaders::ORDER_NUMBER;
        $budgetHeader = ExcelHeaders::BUDGET_IN_CHARGE;
        $orderLetter = Coordinate::stringFromColumnIndex(($headerIndex[$orderHeader] ?? 0) + 1);
        $budgetLetter = Coordinate::stringFromColumnIndex(($headerIndex[$budgetHeader] ?? 0) + 1);
        $reference = $this->identityReference($sheetRows, $orderHeader, $budgetHeader, $orderLetter, $budgetLetter);
        if ($reference === null) {
            return;
        }

        foreach ($sheetRows as &$sheetRow) {
            if ((int) $sheetRow['excelRow'] === $reference['excelRow']) {
                continue;
            }

            $order = trim((string) ($sheetRow['values'][$orderHeader] ?? ''));
            $budget = trim((string) ($sheetRow['values'][$budgetHeader] ?? ''));
            if ($order === '' || $budget === '') {
                continue;
            }

            if ($order !== $reference['order']) {
                $this->addIdentityMismatch(
                    $sheetRow,
                    $projectName,
                    $orderHeader,
                    $orderLetter,
                    'Order No. "'.$order.'" differs from "'.$reference['order'].'" in row '.$reference['excelRow'].'.',
                    'Change the Order No. in row '.$sheetRow['excelRow'].' to "'.$reference['order'].'" so it matches row '.$reference['excelRow'].', then upload the corrected file again.',
                    'Row '.$sheetRow['excelRow'].' Order No. does not match row '.$reference['excelRow'],
                );
            }

            if (JmcProjectFields::normalizeBudget($budget) !== JmcProjectFields::normalizeBudget($reference['budget'])) {
                $this->addIdentityMismatch(
                    $sheetRow,
                    $projectName,
                    $budgetHeader,
                    $budgetLetter,
                    'Budget in Charge "'.$budget.'" differs from "'.$reference['budget'].'" in row '.$reference['excelRow'].'.',
                    'Change the Budget in Charge in row '.$sheetRow['excelRow'].' to "'.$reference['budget'].'" so it matches row '.$reference['excelRow'].', then upload the corrected file again.',
                    'Row '.$sheetRow['excelRow'].' Budget in Charge does not match row '.$reference['excelRow'],
                );
            }
        }
        unset($sheetRow);
    }

    /**
     * The reference is the first row with both identity values present and neither cell already invalid.
     *
     * @param  list<array<string, mixed>>  $sheetRows
     * @return array{excelRow: int, order: string, budget: string}|null
     */
    private function identityReference(
        array $sheetRows,
        string $orderHeader,
        string $budgetHeader,
        string $orderLetter,
        string $budgetLetter,
    ): ?array {
        foreach ($sheetRows as $sheetRow) {
            $order = trim((string) ($sheetRow['values'][$orderHeader] ?? ''));
            $budget = trim((string) ($sheetRow['values'][$budgetHeader] ?? ''));
            if ($order === '' || $budget === '') {
                continue;
            }

            $issues = is_array($sheetRow['issues'] ?? null) ? $sheetRow['issues'] : [];
            if ($this->identityCellAlreadyInvalid($issues, $orderHeader, $orderLetter)
                || $this->identityCellAlreadyInvalid($issues, $budgetHeader, $budgetLetter)) {
                continue;
            }

            return [
                'excelRow' => (int) $sheetRow['excelRow'],
                'order' => $order,
                'budget' => $budget,
            ];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function identityCellAlreadyInvalid(array $issues, string $column, string $letter): bool
    {
        if ($letter === '') {
            return false;
        }

        foreach ($issues as $issue) {
            if ((string) ($issue['columnLetter'] ?? '') !== $letter) {
                continue;
            }

            $name = (string) ($issue['column'] ?? '');
            if ($name === $column || str_starts_with($name, 'Column ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $sheetRow
     */
    private function addIdentityMismatch(
        array &$sheetRow,
        string $projectName,
        string $column,
        string $letter,
        string $problem,
        string $correction,
        string $summary,
    ): void {
        $excelRow = (int) $sheetRow['excelRow'];
        foreach ($sheetRow['issues'] as $issue) {
            if ((int) ($issue['excelRow'] ?? 0) === $excelRow
                && (string) ($issue['column'] ?? '') === $column
                && (string) ($issue['problem'] ?? '') === $problem) {
                return;
            }
        }

        $sheetRow['issues'][] = ImportIssue::make(
            $projectName,
            $excelRow,
            $column,
            $letter,
            $problem,
            $correction,
            $summary,
        );
    }

    /**
     * @param  list<mixed>  $source
     */
    private function rowIsBlank(array $source): bool
    {
        foreach ($source as $cell) {
            if ($this->stringify($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{value: string, issues: list<array<string, mixed>>}
     */
    private function readMappedCell(
        Worksheet $sheet,
        int $colIndex,
        int $excelRow,
        string $header,
        string $worksheet,
        string $letter,
        string $fallback,
    ): array {
        $kind = ExcelHeaders::columnKind($header);
        $address = $letter.$excelRow;
        $issues = [];

        try {
            $cell = $sheet->getCell($address);
        } catch (Throwable) {
            $cell = null;
        }

        if ($kind === 'date') {
            $normalized = $this->readDate($cell, $fallback);
            if ($normalized === false) {
                $issues[] = ImportIssue::make(
                    $worksheet,
                    $excelRow,
                    $header,
                    $letter,
                    'Enter a valid date.',
                    'Enter a real Excel date, or type the date as YYYY-MM-DD, in row '.$excelRow.'.',
                    'Row '.$excelRow.' has an invalid date',
                );

                return ['value' => $fallback, 'issues' => $issues];
            }

            return ['value' => $normalized ?? '', 'issues' => []];
        }

        if ($kind === 'int') {
            $normalized = $this->readInteger($cell, $fallback);
            if ($normalized === false) {
                $issues[] = ImportIssue::make(
                    $worksheet,
                    $excelRow,
                    $header,
                    $letter,
                    'Enter a whole number.',
                    'Replace the value in row '.$excelRow.' with a whole number, or leave the cell blank.',
                    'Row '.$excelRow.' has an invalid number',
                );

                return ['value' => $fallback, 'issues' => $issues];
            }

            return ['value' => $normalized ?? '', 'issues' => []];
        }

        $value = $fallback;
        if ($cell instanceof Cell) {
            $raw = $cell->getValue();
            if (is_int($raw) || is_float($raw)) {
                $value = $this->numberToString($raw);
            }
        }

        $max = ExcelHeaders::maxLength($header);
        if ($max !== null && mb_strlen($value) > $max) {
            $issues[] = ImportIssue::make(
                $worksheet,
                $excelRow,
                $header,
                $letter,
                'This value is longer than '.$max.' characters.',
                'Shorten this cell to '.$max.' characters or fewer.',
                'Row '.$excelRow.' is too long',
            );
        }

        return ['value' => $value, 'issues' => $issues];
    }

    /**
     * @return string|null|false Null when blank, false when invalid.
     */
    private function readDate(?Cell $cell, string $fallback): string|null|false
    {
        if ($cell instanceof Cell && Date::isDateTime($cell)) {
            $raw = $cell->getValue();
            if (is_numeric($raw)) {
                $date = Date::excelToDateTimeObject((float) $raw);

                return $date->format('Y-m-d');
            }
        }

        $text = trim($fallback);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $text, $matches) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);
            if ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $matches[1]) {
                return $matches[1];
            }

            return false;
        }

        $formats = ['Y-m-d', 'Y/m/d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'd-M-Y', 'j-M-Y', 'M j, Y', 'F j, Y'];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $text);
            if ($parsed instanceof DateTimeImmutable && $parsed->format($format) === $text) {
                return $parsed->format('Y-m-d');
            }
        }

        return false;
    }

    /**
     * @return string|null|false Null when blank, false when invalid.
     */
    private function readInteger(?Cell $cell, string $fallback): string|null|false
    {
        $raw = null;
        if ($cell instanceof Cell) {
            try {
                $raw = $cell->getCalculatedValue();
            } catch (Throwable) {
                $raw = $cell->getValue();
            }
        }

        if (is_int($raw) || (is_float($raw) && floor($raw) == $raw)) {
            $number = (int) $raw;
            if ($number < 0) {
                return false;
            }

            return (string) $number;
        }

        $text = trim($fallback);
        if ($text === '') {
            return null;
        }

        $text = str_replace(',', '', $text);
        if (preg_match('/^\d+$/', $text) !== 1) {
            return false;
        }

        return $text;
    }

    private function numberToString(int|float $number): string
    {
        if (is_float($number) && floor($number) != $number) {
            return rtrim(rtrim(sprintf('%.10F', $number), '0'), '.');
        }

        return sprintf('%.0F', $number);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileIssue(string $worksheet, string $problem, string $correction): array
    {
        return ImportIssue::make($worksheet, 0, 'File', '', $problem, $correction, $problem);
    }

    private function stringify(mixed $value): string
    {
        if ($value instanceof RichText) {
            return trim($value->getPlainText());
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return trim((string) $value);
    }
}
