<?php

namespace Tests\Unit;

use App\Services\Jmc\ExcelDrawingListReader;
use App\Services\Jmc\ImportIssue;
use App\Services\Jmc\ExcelHeaders;
use App\Services\Jmc\JmcErrorReportExporter;
use App\Services\Jmc\JmcPreviewQuery;
use App\Services\Jmc\JmcRowClassifier;
use App\Services\Jmc\JmcTemplateExporter;
use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class JmcImportWorkflowTest extends TestCase
{
    public function test_template_matches_the_importer_and_skips_helper_sheets(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, (new JmcTemplateExporter)->binary());

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Project Name');
        $this->assertNotNull($sheet);

        $headers = [];
        foreach (array_merge(ExcelHeaders::baseList(), [ExcelHeaders::KHI_PREP]) as $index => $header) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $headers[] = $sheet->getCell($letter.'1')->getValue();
        }
        $this->assertSame(array_merge(ExcelHeaders::baseList(), [ExcelHeaders::KHI_PREP]), $headers);

        $instructions = $spreadsheet->getSheetByName('Instructions');
        $this->assertNotNull($instructions);
        $instructionText = '';
        foreach ($instructions->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $instructionText .= ' '.(string) $cell->getValue();
            }
        }
        $this->assertStringContainsString('project name', $instructionText);
        $this->assertStringContainsString('up to 31 characters', $instructionText);
        $this->assertStringContainsString('Instructions and Example sheets are not imported', $instructionText);
        $this->assertStringNotContainsString('worksheet name can be up to 100', strtolower($instructionText));

        $inspected = (new ExcelDrawingListReader)->inspect($path);
        $this->assertSame([], $inspected['rows']);
        $this->assertNotSame([], $inspected['fileIssues']);
        $this->assertStringContainsString('No importable rows', $inspected['fileIssues'][0]['problem']);
    }

    public function test_reader_keeps_excel_row_numbers_and_reports_cell_problems(): void
    {
        $path = $this->workbook(function (Spreadsheet $spreadsheet) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Alpha');
            $this->headers($sheet);
            $sheet->setCellValue('C4', 'Pump');
            $sheet->setCellValue('G4', 'Layout');
            $sheet->setCellValue('L4', '8');
            $sheet->setCellValue('C6', 'Pump');
            $sheet->setCellValue('K6', 'not-a-date');
            $sheet->setCellValue('L6', 'eight');
            $sheet->setCellValue('C8', 'Pump');
            $sheet->setCellValue('G8', 'Layout');
            $sheet->setCellValue('A8', 'bad@order');
        });

        $rows = (new ExcelDrawingListReader)->inspect($path)['rows'];
        $this->assertSame([4, 6, 8], array_column($rows, 'excelRow'));

        $row4 = $rows[0];
        $this->assertSame('Layout', $row4['values'][ExcelHeaders::DESCRIPTION]);
        $this->assertSame('8', $row4['values'][ExcelHeaders::EXPECTED_MANHOUR]);
        $this->assertSame([], $row4['issues']);

        $problems = array_column($rows[1]['issues'], 'problem');
        $this->assertContains('Job Request Description is required.', $problems);
        $this->assertContains('Enter a valid date.', $problems);
        $this->assertContains('Enter a whole number.', $problems);

        $letters = array_column($rows[2]['issues'], 'columnLetter');
        $this->assertContains('A', $letters);
    }

    public function test_excel_dates_are_read_from_the_serial_value(): void
    {
        $path = $this->workbook(function (Spreadsheet $spreadsheet) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Dated');
            $this->headers($sheet);
            $sheet->setCellValue('C2', 'Pump');
            $sheet->setCellValue('G2', 'Dated job');
            $sheet->setCellValue('K2', Date::PHPToExcel(new \DateTimeImmutable('2026-03-04')));
            $sheet->getStyle('K2')->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        });

        $rows = (new ExcelDrawingListReader)->inspect($path)['rows'];
        $this->assertSame('2026-03-04', $rows[0]['values'][ExcelHeaders::KDT_DEADLINE]);
        $this->assertSame([], $rows[0]['issues']);
    }

    public function test_classifier_skips_existing_jobs_and_keeps_other_projects_separate(): void
    {
        $inspected = [
            'fileIssues' => [],
            'rows' => [
                $this->row('Alpha', 2, 'Pump', 'Layout', '8'),
                $this->row('Alpha', 3, 'Pump', 'New job', '4'),
                $this->row('Beta', 2, 'Pump', 'Layout', '8'),
                $this->row('Alpha', 4, 'Pump', 'Twin', '1', 'Shared drawing'),
                $this->row('Alpha', 5, 'Pump', 'Twin copy', '1', 'Shared drawing'),
            ],
        ];

        $snapshot = [
            'projects' => [
                'group|alpha' => ['id' => '10', 'name' => 'Alpha'],
                'group|beta' => ['id' => '11', 'name' => 'Beta'],
            ],
            'items' => [
                '10|eng|pump' => ['id' => '70', 'name' => 'Pump'],
                '11|eng|pump' => ['id' => '71', 'name' => 'Pump'],
            ],
            'jobs' => [
                '10|70|eng|layout' => true,
            ],
            'drawings' => [
                '10|70|eng|shared drawing' => ['kept job'],
            ],
        ];

        $classified = (new JmcRowClassifier)->classify($inspected, $snapshot, 'ENG');
        $results = array_column($classified['rows'], 'result');
        $this->assertSame(['exists', 'new', 'new', 'error', 'error'], $results);
        $this->assertSame('Already in Web JMR and will be skipped.', $classified['rows'][0]['details']);
        $this->assertStringContainsString('different Job Request Description', $classified['rows'][3]['issues'][0]['problem']);
    }

    public function test_matching_project_values_are_already_exists(): void
    {
        $classified = $this->classifySample('CEM');

        $this->assertSame('exists', $classified['rows'][0]['result']);
        $this->assertSame([], $classified['rows'][0]['issues']);
    }

    public function test_unrecognized_budget_is_an_error_instead_of_already_exists(): void
    {
        $classified = $this->classifySample('CEMs');
        $row = $classified['rows'][0];

        $this->assertSame('error', $row['result']);
        $this->assertSame('Project Name', $row['issues'][0]['worksheet']);
        $this->assertSame(2, $row['issues'][0]['excelRow']);
        $this->assertSame('B', $row['issues'][0]['columnLetter']);
        $this->assertSame(ExcelHeaders::BUDGET_IN_CHARGE, $row['issues'][0]['column']);
        $this->assertSame(
            'Excel row 2, Budget in Charge (B): \'CEMs\' is not a recognized value.',
            $row['issues'][0]['problem'],
        );
        $this->assertStringNotContainsString('differs from', $row['issues'][0]['problem']);

        $report = $this->tempPath();
        file_put_contents($report, (new JmcErrorReportExporter)->binary([], $classified['rows']));
        $sheet = IOFactory::load($report)->getActiveSheet();
        $this->assertSame('Project Name', $sheet->getCell('A2')->getValue());
        $this->assertSame(2, (int) $sheet->getCell('B2')->getValue());
        $this->assertSame('B', $sheet->getCell('D2')->getValue());
        $this->assertSame($row['issues'][0]['problem'], $sheet->getCell('E2')->getValue());
    }

    public function test_valid_budget_conflict_is_reported_without_creating_another_record(): void
    {
        $classified = $this->classifySample('SYS');
        $row = $classified['rows'][0];

        $this->assertSame('error', $row['result']);
        $this->assertSame(
            'Budget in Charge \'SYS\' differs from the existing value \'CEM\' for this project.',
            $row['issues'][0]['problem'],
        );
    }

    public function test_cm_is_the_only_budget_alias_and_matches_cem(): void
    {
        $this->assertSame('CEM', \App\Services\Jmc\JmcProjectFields::normalizeBudget('CM'));
        $this->assertSame('CEMs', \App\Services\Jmc\JmcProjectFields::normalizeBudget('CEMs'));
        $this->assertSame('exists', $this->classifySample('CM')['rows'][0]['result']);
    }

    public function test_same_job_under_another_project_stays_new(): void
    {
        $snapshot = $this->sampleSnapshot();
        $snapshot['projects']['group|other project'] = [
            'id' => '2000',
            'name' => 'Other Project',
            'order' => '12345671111',
            'budget' => 'CEM',
        ];
        $snapshot['items']['2000|sys|sample item'] = ['id' => '3000', 'name' => 'Sample item'];
        $row = $this->sampleRow('CEM');
        $row['worksheet'] = 'Other Project';

        $classified = (new JmcRowClassifier)->classify([
            'fileIssues' => [],
            'rows' => [$row],
        ], $snapshot, 'SYS');

        $this->assertSame('new', $classified['rows'][0]['result']);
    }

    public function test_job_attributes_with_a_separate_edit_workflow_do_not_conflict(): void
    {
        $classified = $this->classifySample('CEM', [
            ExcelHeaders::EXPECTED_MANHOUR => '99',
            ExcelHeaders::DOCUMENT_NAME => 'Different drawing',
            ExcelHeaders::KDT_DEADLINE => '2026-03-01',
            ExcelHeaders::NUMBER_OF_SHEET => '4',
        ]);

        $this->assertSame('exists', $classified['rows'][0]['result']);
        $this->assertSame([], $classified['rows'][0]['issues']);
    }

    public function test_one_row_reports_every_project_conflict_and_cell_error(): void
    {
        $row = $this->sampleRow('CEMs', [ExcelHeaders::ORDER_NUMBER => '999']);
        $row['issues'][] = ImportIssue::make(
            'Project Name',
            2,
            ExcelHeaders::EXPECTED_MANHOUR,
            'L',
            'Enter a whole number.',
            'Enter a whole number.',
            'Expected Manhour is invalid',
        );

        $classified = (new JmcRowClassifier)->classify([
            'fileIssues' => [],
            'rows' => [$row],
        ], $this->sampleSnapshot(), 'SYS');
        $problems = array_column($classified['rows'][0]['issues'], 'problem');

        $this->assertSame('error', $classified['rows'][0]['result']);
        $this->assertSame([
            'Enter a whole number.',
            'Order No. \'999\' differs from the existing value \'12345671111\' for this project.',
            'Excel row 2, Budget in Charge (B): \'CEMs\' is not a recognized value.',
        ], $problems);
    }

    public function test_reader_treats_cm_and_cem_as_the_same_budget(): void
    {
        $path = $this->identityWorkbook([
            2 => ['6969696-9696', 'CM'],
            3 => ['6969696-9696', 'CEM'],
        ]);

        $rows = $this->rowsByExcelRow($path);
        $this->assertSame([], $rows[2]['issues']);
        $this->assertSame([], $rows[3]['issues']);
    }

    public function test_legacy_budget_matching_the_stored_project_is_not_rejected(): void
    {
        $snapshot = $this->sampleSnapshot();
        $snapshot['projects']['group|project name']['budget'] = 'MCH';
        $snapshot['budgets'] = ['CEM' => true];

        $classified = (new JmcRowClassifier)->classify([
            'fileIssues' => [],
            'rows' => [$this->sampleRow('MCH')],
        ], $snapshot, 'SYS');

        $this->assertSame('exists', $classified['rows'][0]['result']);
    }

    public function test_repeated_file_rows_are_not_treated_as_database_duplicates(): void
    {
        $inspected = [
            'fileIssues' => [],
            'rows' => [
                $this->row('Alpha', 2, 'Pump', 'Fresh', '2'),
                $this->row('Alpha', 5, 'Pump', 'Fresh', '2'),
            ],
        ];
        $snapshot = [
            'projects' => [],
            'items' => [],
            'jobs' => [],
            'drawings' => [],
        ];

        $classified = (new JmcRowClassifier)->classify($inspected, $snapshot, 'ENG');
        $this->assertSame('new', $classified['rows'][0]['result']);
        $this->assertSame('error', $classified['rows'][1]['result']);
        $this->assertStringContainsString('row 2', $classified['rows'][1]['issues'][0]['problem']);
    }

    public function test_error_report_lists_worksheet_row_and_correction(): void
    {
        $binary = (new JmcErrorReportExporter)->binary(
            [],
            [[
                'result' => 'error',
                'worksheet' => 'Alpha',
                'excelRow' => 9,
                'details' => 'This is not a valid date.',
                'issues' => [[
                    'worksheet' => 'Alpha',
                    'excelRow' => 9,
                    'column' => 'KDT Deadline',
                    'columnLetter' => 'K',
                    'problem' => 'This is not a valid date.',
                    'correction' => 'Enter a real Excel date.',
                ]],
            ]],
        );

        $path = $this->tempPath();
        file_put_contents($path, $binary);
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame('Worksheet', $sheet->getCell('A1')->getValue());
        $this->assertSame('Alpha', $sheet->getCell('A2')->getValue());
        $this->assertSame(9, (int) $sheet->getCell('B2')->getValue());
        $this->assertSame('K', $sheet->getCell('D2')->getValue());
        $this->assertSame('Enter a real Excel date.', $sheet->getCell('F2')->getValue());
    }

    public function test_preview_query_filters_and_pages_on_the_server_result(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = [
                'project' => $i === 3 ? 'Find me' : 'Alpha',
                'itemOfWorks' => 'Pump',
                'jobRequest' => 'Job '.$i,
                'result' => $i % 2 === 0 ? 'exists' : 'new',
            ];
        }
        $preview = [
            'token' => 'abc',
            'filename' => 'list.xlsx',
            'group' => 'ENG',
            'summary' => ['dataRows' => 25, 'new' => 13, 'exists' => 12, 'errors' => 0, 'fileProblems' => 0, 'attention' => 0],
            'highlightedIssues' => [],
            'issueTotal' => 0,
            'rows' => $rows,
        ];

        $page = (new JmcPreviewQuery)->page($preview, 'new', 'find', 1, 20);
        $this->assertSame(1, $page['total']);
        $this->assertSame('Find me', $page['rows'][0]['project']);

        $second = (new JmcPreviewQuery)->page($preview, 'all', '', 2, 20);
        $this->assertCount(5, $second['rows']);
    }

    public function test_order_number_mismatch_is_reported_without_a_budget_error(): void
    {
        $path = $this->identityWorkbook([
            2 => ['6969696-9696', 'SYS'],
            3 => ['1234567-123', 'SYS'],
        ]);

        $rows = $this->rowsByExcelRow($path);
        $this->assertSame([], $rows[2]['issues']);

        $issues = $rows[3]['issues'];
        $this->assertCount(1, $issues);
        $this->assertSame(ExcelHeaders::ORDER_NUMBER, $issues[0]['column']);
        $this->assertSame('A', $issues[0]['columnLetter']);
        $this->assertSame(3, $issues[0]['excelRow']);
        $this->assertSame('Order No. "1234567-123" differs from "6969696-9696" in row 2.', $issues[0]['problem']);
    }

    public function test_budget_in_charge_mismatch_is_reported_without_an_order_error(): void
    {
        $path = $this->identityWorkbook([
            2 => ['6969696-9696', 'SYS'],
            4 => ['6969696-9696', 'SYSST'],
        ]);

        $rows = $this->rowsByExcelRow($path);
        $this->assertSame([], $rows[2]['issues']);
        $this->assertArrayNotHasKey(3, $rows);

        $issues = $rows[4]['issues'];
        $this->assertCount(1, $issues);
        $this->assertSame(ExcelHeaders::BUDGET_IN_CHARGE, $issues[0]['column']);
        $this->assertSame('B', $issues[0]['columnLetter']);
        $this->assertSame(4, $issues[0]['excelRow']);
        $this->assertSame('Budget in Charge "SYSST" differs from "SYS" in row 2.', $issues[0]['problem']);
    }

    public function test_both_identity_fields_report_separate_mismatches(): void
    {
        $path = $this->identityWorkbook([
            2 => ['6969696-9696', 'SYS'],
            3 => ['1234567-123', 'SYSST'],
            4 => ['6969696-9696', 'SYSST'],
        ]);

        $rows = $this->rowsByExcelRow($path);
        $this->assertSame([], $rows[2]['issues']);

        $row3 = $rows[3]['issues'];
        $this->assertSame([
            'Order No. "1234567-123" differs from "6969696-9696" in row 2.',
            'Budget in Charge "SYSST" differs from "SYS" in row 2.',
        ], array_column($row3, 'problem'));
        $this->assertSame(['A', 'B'], array_column($row3, 'columnLetter'));
        $this->assertSame(
            [ExcelHeaders::ORDER_NUMBER, ExcelHeaders::BUDGET_IN_CHARGE],
            array_column($row3, 'column'),
        );

        $row4 = $rows[4]['issues'];
        $this->assertSame(['Budget in Charge "SYSST" differs from "SYS" in row 2.'], array_column($row4, 'problem'));
        $this->assertSame(['B'], array_column($row4, 'columnLetter'));
        $this->assertNotContains(
            'This worksheet uses more than one Order No. and Budget in Charge. A project can only have one.',
            array_merge(array_column($row3, 'problem'), array_column($row4, 'problem')),
        );
    }

    public function test_matching_order_and_budget_do_not_report_mismatches(): void
    {
        $path = $this->identityWorkbook([
            2 => ['6969696-9696', 'SYS'],
            3 => ['6969696-9696', 'SYS'],
            5 => ['6969696-9696', 'SYS'],
        ]);

        $rows = $this->rowsByExcelRow($path);
        $this->assertSame([2, 3, 5], array_keys($rows));
        foreach ($rows as $row) {
            $this->assertSame([], $row['issues']);
        }
    }

    public function test_one_row_can_carry_identity_mismatches_and_another_cell_error(): void
    {
        $path = $this->workbook(function (Spreadsheet $spreadsheet) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Alpha');
            $this->headers($sheet);
            $this->fillIdentity($sheet, 2, '6969696-9696', 'SYS');
            $this->fillIdentity($sheet, 3, '1234567-123', 'SYSST');
            $sheet->setCellValue('K3', 'not-a-date');
        });

        $issues = $this->rowsByExcelRow($path)[3]['issues'];
        $this->assertSame([
            'Enter a valid date.',
            'Order No. "1234567-123" differs from "6969696-9696" in row 2.',
            'Budget in Charge "SYSST" differs from "SYS" in row 2.',
        ], array_column($issues, 'problem'));
        $this->assertSame(['K', 'A', 'B'], array_column($issues, 'columnLetter'));
    }

    public function test_error_report_maps_identity_mismatches_to_excel_columns(): void
    {
        $path = $this->identityWorkbook([
            2 => ['6969696-9696', 'SYS'],
            3 => ['1234567-123', 'SYSST'],
            4 => ['6969696-9696', 'SYSST'],
        ]);
        $classified = $this->classifyWorkbook($path);

        $preview = [];
        foreach ($classified['rows'] as $row) {
            foreach ($row['issues'] as $issue) {
                $preview[] = [
                    (int) $row['excelRow'],
                    (string) $issue['column'],
                    (string) $issue['columnLetter'],
                    (string) $issue['problem'],
                ];
            }
        }

        $binary = (new JmcErrorReportExporter)->binary($classified['fileIssues'], $classified['rows']);
        $reportPath = $this->tempPath();
        file_put_contents($reportPath, $binary);
        $sheet = IOFactory::load($reportPath)->getActiveSheet();

        $reported = [];
        for ($line = 2; $line <= 4; $line++) {
            $reported[] = [
                (int) $sheet->getCell('B'.$line)->getValue(),
                (string) $sheet->getCell('C'.$line)->getValue(),
                (string) $sheet->getCell('D'.$line)->getValue(),
                (string) $sheet->getCell('E'.$line)->getValue(),
            ];
        }

        $expected = [
            [3, ExcelHeaders::ORDER_NUMBER, 'A', 'Order No. "1234567-123" differs from "6969696-9696" in row 2.'],
            [3, ExcelHeaders::BUDGET_IN_CHARGE, 'B', 'Budget in Charge "SYSST" differs from "SYS" in row 2.'],
            [4, ExcelHeaders::BUDGET_IN_CHARGE, 'B', 'Budget in Charge "SYSST" differs from "SYS" in row 2.'],
        ];
        $this->assertSame($expected, $preview);
        $this->assertSame($expected, $reported);
        $this->assertSame('Alpha', $sheet->getCell('A2')->getValue());
        $this->assertNull($sheet->getCell('A5')->getValue());
    }

    public function test_blank_rows_keep_excel_row_numbers_on_identity_mismatches(): void
    {
        $path = $this->identityWorkbook([
            2 => ['6969696-9696', 'SYS'],
            5 => ['1234567-123', 'SYS'],
        ]);

        $issues = $this->rowsByExcelRow($path)[5]['issues'];
        $this->assertCount(1, $issues);
        $this->assertSame(5, $issues[0]['excelRow']);
        $this->assertSame('A', $issues[0]['columnLetter']);
        $this->assertSame('Order No. "1234567-123" differs from "6969696-9696" in row 2.', $issues[0]['problem']);
    }

    public function test_invalid_reference_row_is_not_used_as_the_expected_value(): void
    {
        $path = $this->identityWorkbook([
            2 => ['bad@order', 'SYS'],
            3 => ['6969696-9696', 'SYS'],
            4 => ['6969696-9696', 'SYSST'],
        ]);

        $rows = $this->rowsByExcelRow($path);
        $this->assertSame(
            ['Budget in Charge "SYSST" differs from "SYS" in row 3.'],
            array_column($rows[4]['issues'], 'problem'),
        );
        $this->assertNotContains(
            'Order No. "6969696-9696" differs from "bad@order" in row 2.',
            array_column($rows[3]['issues'], 'problem'),
        );

        $unusable = $this->identityWorkbook([
            2 => ['bad@one', 'SYS'],
            3 => ['bad@two', 'OTHER'],
        ]);
        $unusableRows = $this->rowsByExcelRow($unusable);
        foreach ($unusableRows as $row) {
            foreach ($row['issues'] as $issue) {
                $this->assertStringNotContainsString('differs from', (string) $issue['problem']);
            }
        }
    }

    public function test_identity_mismatch_uses_the_worksheet_column_letters(): void
    {
        $path = $this->workbook(function (Spreadsheet $spreadsheet) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Alpha');
            $headers = ExcelHeaders::baseList();
            $headers[0] = ExcelHeaders::BUDGET_IN_CHARGE;
            $headers[1] = ExcelHeaders::ORDER_NUMBER;
            foreach ($headers as $index => $header) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
            }
            $sheet->setCellValue('A2', 'SYS');
            $sheet->setCellValue('B2', '6969696-9696');
            $sheet->setCellValue('C2', 'Pump');
            $sheet->setCellValue('G2', 'Layout');
            $sheet->setCellValue('A3', 'SYSST');
            $sheet->setCellValue('B3', '1234567-123');
            $sheet->setCellValue('C3', 'Pump');
            $sheet->setCellValue('G3', 'Layout');
        });

        $issues = $this->rowsByExcelRow($path)[3]['issues'];
        $this->assertSame([
            ['column' => ExcelHeaders::ORDER_NUMBER, 'columnLetter' => 'B'],
            ['column' => ExcelHeaders::BUDGET_IN_CHARGE, 'columnLetter' => 'A'],
        ], array_map(
            fn (array $issue) => ['column' => $issue['column'], 'columnLetter' => $issue['columnLetter']],
            $issues,
        ));
    }

    /**
     * @param  callable(Spreadsheet): void  $build
     */
    private function workbook(callable $build): string
    {
        $spreadsheet = new Spreadsheet;
        $build($spreadsheet);
        $path = $this->tempPath();
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function headers(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        foreach (ExcelHeaders::baseList() as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    private function identityWorkbook(array $rows): string
    {
        return $this->workbook(function (Spreadsheet $spreadsheet) use ($rows) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Alpha');
            $this->headers($sheet);
            foreach ($rows as $excelRow => $values) {
                $this->fillIdentity($sheet, $excelRow, $values[0], $values[1]);
            }
        });
    }

    private function fillIdentity(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $excelRow,
        string $order,
        string $budget,
    ): void {
        $sheet->setCellValue('A'.$excelRow, $order);
        $sheet->setCellValue('B'.$excelRow, $budget);
        $sheet->setCellValue('C'.$excelRow, 'Pump');
        $sheet->setCellValue('G'.$excelRow, 'Layout '.$excelRow);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsByExcelRow(string $path): array
    {
        $indexed = [];
        foreach ((new ExcelDrawingListReader)->inspect($path)['rows'] as $row) {
            $indexed[(int) $row['excelRow']] = $row;
        }

        return $indexed;
    }

    /**
     * @return array{fileIssues: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    private function classifyWorkbook(string $path): array
    {
        return (new JmcRowClassifier)->classify(
            (new ExcelDrawingListReader)->inspect($path),
            [
                'projects' => [],
                'items' => [],
                'jobs' => [],
                'drawings' => [],
            ],
            'ENG',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $project,
        int $excelRow,
        string $item,
        string $job,
        string $hours,
        string $drawing = '',
    ): array {
        return [
            'worksheet' => $project,
            'excelRow' => $excelRow,
            'issues' => [],
            'values' => [
                ExcelHeaders::ITEM_OF_WORKS => $item,
                ExcelHeaders::DESCRIPTION => $job,
                ExcelHeaders::EXPECTED_MANHOUR => $hours,
                ExcelHeaders::DOCUMENT_NAME => $drawing,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function sampleRow(string $budget, array $overrides = []): array
    {
        return [
            'worksheet' => 'Project Name',
            'excelRow' => 2,
            'issues' => [],
            'columns' => [
                ExcelHeaders::ORDER_NUMBER => 'A',
                ExcelHeaders::BUDGET_IN_CHARGE => 'B',
            ],
            'values' => array_merge([
                ExcelHeaders::ORDER_NUMBER => '12345671111',
                ExcelHeaders::BUDGET_IN_CHARGE => $budget,
                ExcelHeaders::ITEM_OF_WORKS => 'Sample item',
                ExcelHeaders::DESCRIPTION => 'Sample job request. This Example sheet is not imported.',
                ExcelHeaders::EXPECTED_MANHOUR => '8',
                ExcelHeaders::DOCUMENT_NAME => 'Sample drawing',
            ], $overrides),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleSnapshot(): array
    {
        return [
            'projects' => [
                'group|project name' => [
                    'id' => '1064',
                    'name' => 'Project Name',
                    'order' => '12345671111',
                    'budget' => 'CEM',
                ],
            ],
            'items' => [
                '1064|sys|sample item' => ['id' => '20475', 'name' => 'Sample item'],
            ],
            'jobs' => [
                '1064|20475|sys|sample job request. this example sheet is not imported.' => true,
            ],
            'drawings' => [],
            'budgets' => ['CEM' => true, 'SYS' => true],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array{fileIssues: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    private function classifySample(string $budget, array $overrides = []): array
    {
        return (new JmcRowClassifier)->classify([
            'fileIssues' => [],
            'rows' => [$this->sampleRow($budget, $overrides)],
        ], $this->sampleSnapshot(), 'SYS');
    }

    private function tempPath(): string
    {
        return tempnam(sys_get_temp_dir(), 'jmc').'.xlsx';
    }
}
