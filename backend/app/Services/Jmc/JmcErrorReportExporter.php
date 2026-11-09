<?php

namespace App\Services\Jmc;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class JmcErrorReportExporter
{
    /**
     * @param  list<array<string, mixed>>  $fileIssues
     * @param  list<array<string, mixed>>  $rows
     */
    public function binary(array $fileIssues, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Errors');
        $headers = ['Worksheet', 'Excel row', 'Column', 'Column letter', 'Problem', 'Recommended correction'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $line = 2;
        foreach ($fileIssues as $issue) {
            $this->writeIssue($sheet, $line, $issue);
            $line++;
        }
        foreach ($rows as $row) {
            if (($row['result'] ?? '') !== 'error') {
                continue;
            }
            $issues = $row['issues'] ?? [];
            if ($issues === []) {
                $issues = [ImportIssue::make(
                    (string) ($row['worksheet'] ?? ''),
                    (int) ($row['excelRow'] ?? 0),
                    '',
                    '',
                    (string) ($row['details'] ?? 'This row needs attention.'),
                    'Correct the Excel file and upload it again.',
                    '',
                )];
            }
            foreach ($issues as $issue) {
                $this->writeIssue($sheet, $line, $issue);
                $line++;
            }
        }

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $contents = ob_get_clean();

        return $contents === false ? '' : $contents;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function writeIssue(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $line, array $issue): void
    {
        $sheet->setCellValue([1, $line], (string) ($issue['worksheet'] ?? ''));
        $row = (int) ($issue['excelRow'] ?? 0);
        $sheet->setCellValue([2, $line], $row > 0 ? $row : '');
        $sheet->setCellValue([3, $line], (string) ($issue['column'] ?? ''));
        $sheet->setCellValue([4, $line], (string) ($issue['columnLetter'] ?? ''));
        $sheet->setCellValue([5, $line], (string) ($issue['problem'] ?? ''));
        $sheet->setCellValue([6, $line], (string) ($issue['correction'] ?? ''));
    }
}
