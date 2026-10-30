<?php

namespace App\Services\Jmc;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class ExcelDrawingListReader
{
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

    private function stringify(mixed $value): string
    {
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
