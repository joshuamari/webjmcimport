<?php

namespace App\Services\Jmc;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class JmcTemplateExporter
{
    public function binary(): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('JMC Import Template')
            ->setSubject('Blank template for new Job Manhour Category records');

        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle('Instructions');
        $this->writeInstructions($instructions);

        $entries = $spreadsheet->createSheet();
        $entries->setTitle('Project Name');
        $this->writeHeaders($entries);

        $example = $spreadsheet->createSheet();
        $example->setTitle('Example');
        $this->writeHeaders($example);
        $this->writeExample($example);

        $spreadsheet->setActiveSheetIndex(1);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $contents = ob_get_clean();

        return $contents === false ? '' : $contents;
    }

    private function writeInstructions(Worksheet $sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(92);
        $sheet->getStyle('A:B')->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A:B')->getFont()->setName('Calibri')->setSize(11);

        $blocks = [
            ['title', 'JMC Import template', ''],
            ['lead', '', 'Each worksheet is one project. The worksheet name is the project name, not the Order No. or the Budget in Charge.'],
            ['heading', 'Getting Started', ''],
            ['step', '1', 'Rename the Project Name worksheet to your project name.'],
            ['step', '2', 'Fill in one row for each job request.'],
            ['step', '3', 'Save the workbook as .xlsx.'],
            ['step', '4', 'In JMC Import, select the group, upload the file, review the result, then confirm.'],
            ['heading', 'Worksheet Rules', ''],
            ['item', '', 'One project per worksheet.'],
            ['item', '', 'Use the project name as the worksheet name. Excel allows up to 31 characters, and a worksheet name cannot contain : \\ / ? * [ ].'],
            ['item', '', 'When both Order No. and Budget in Charge are filled in, use the same pair on every row of that worksheet. A row with either cell blank is not compared.'],
            ['item', '', 'Use a separate worksheet for each project. Copy the sheet and rename the copy.'],
            ['item', '', 'The Instructions and Example sheets are not imported. Sheets named for built-in activities, such as OTHERS, LEAVE, MANAGEMENT, and MATERIAL LIST, are also skipped.'],
            ['item', '', 'Blank rows are ignored.'],
            ['heading', 'Required Fields', ''],
            ['item', '', 'The header row must include every column from the Project Name sheet except KHI Preparing Manhour (Hr), which is optional.'],
            ['item', '', 'On every data row, Item of Works and Job Request Description are required. Every other cell may be left blank.'],
            ['item', '', 'The Order No. column is named for a 7-digit and 4-digit number. Other text is accepted, up to 100 characters.'],
            ['heading', 'Data Formats', ''],
            ['item', '', 'Dates can be a real Excel date, YYYY-MM-DD (for example, 2026-01-15), or a common date such as 01/15/2026. Leave a date blank if you do not have one.'],
            ['item', '', 'No. of Sheet, Expected Manhour, and KHI Preparing Manhour must be whole numbers when filled in. Blank is allowed. Decimals are not.'],
            ['item', '', 'Paper Size can be up to 2 characters. Order No., Budget in Charge, Item of Works, Drawing / Document Name, Job Request Description, and KHI In charge can be up to 100 characters.'],
            ['item', '', 'The worksheet name and any filled cell cannot contain \\ | ! $ % = * ? @ \' < > or » « £ § € { }.'],
            ['heading', 'Import Behavior', ''],
            ['item', '', 'The file may contain only new records, or your existing JMC list.'],
            ['item', '', 'A job request already stored for the same project and item of works is skipped. Existing JMC records are not overwritten.'],
            ['item', '', 'The same job request under a different project is a different record. Do not repeat the same item of works and job request in the file.'],
            ['item', '', 'A drawing name already used by a different job request under the same item of works is not accepted.'],
            ['item', '', 'A Budget in Charge of CM is saved as CEM. Review the results before you confirm the import.'],
        ];

        $row = 1;
        foreach ($blocks as [$kind, $label, $text]) {
            if ($kind === 'title') {
                $sheet->setCellValue('A'.$row, $label);
                $sheet->mergeCells('A'.$row.':B'.$row);
                $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16);
                $sheet->getRowDimension($row)->setRowHeight(28);
            } elseif ($kind === 'lead') {
                $sheet->setCellValue('A'.$row, $text);
                $sheet->mergeCells('A'.$row.':B'.$row);
                $sheet->getRowDimension($row)->setRowHeight(32);
            } elseif ($kind === 'heading') {
                $sheet->setCellValue('A'.$row, $label);
                $sheet->mergeCells('A'.$row.':B'.$row);
                $sheet->getStyle('A'.$row.':B'.$row)->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('A'.$row.':B'.$row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E8EEF4');
                $sheet->getRowDimension($row)->setRowHeight(22);
            } else {
                $sheet->setCellValue('A'.$row, $label);
                $sheet->setCellValue('B'.$row, $text);
                $sheet->getRowDimension($row)->setRowHeight(36);
            }
            $row++;
        }

        $sheet->getStyle('A1:B'.($row - 1))->getAlignment()->setWrapText(true);
    }

    private function writeHeaders(Worksheet $sheet): void
    {
        $headers = ExcelHeaders::baseList();
        $headers[] = ExcelHeaders::KHI_PREP;
        foreach ($headers as $index => $header) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($letter.'1', $header);
            $sheet->getColumnDimension($letter)->setWidth(max(18, min(42, mb_strlen($header) + 2)));
        }

        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$last.'1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E8EEF4');
        $sheet->freezePane('A2');

        $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('B:C')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        foreach ([ExcelHeaders::KHI_REQUESTED_DATE, ExcelHeaders::KHI_DEADLINE, ExcelHeaders::KDT_DEADLINE] as $header) {
            $index = array_search($header, $headers, true);
            if ($index === false) {
                continue;
            }
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getStyle($letter.':'.$letter)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }

        $itemIndex = array_search(ExcelHeaders::ITEM_OF_WORKS, $headers, true);
        $jobIndex = array_search(ExcelHeaders::DESCRIPTION, $headers, true);
        if ($itemIndex !== false) {
            $comment = $sheet->getComment(Coordinate::stringFromColumnIndex($itemIndex + 1).'1');
            $comment->getText()->createTextRun('Required. The item of works under this project.');
            $comment->setWidth('220px');
            $comment->setHeight('70px');
        }
        if ($jobIndex !== false) {
            $comment = $sheet->getComment(Coordinate::stringFromColumnIndex($jobIndex + 1).'1');
            $comment->getText()->createTextRun('Required. The job request description. This is how an existing record is recognized.');
            $comment->setWidth('240px');
            $comment->setHeight('80px');
        }

    }

    private function writeExample(Worksheet $sheet): void
    {
        $headers = ExcelHeaders::baseList();
        $headers[] = ExcelHeaders::KHI_PREP;
        $sample = [
            ExcelHeaders::ORDER_NUMBER => '12345671111',
            ExcelHeaders::BUDGET_IN_CHARGE => 'CEM',
            ExcelHeaders::ITEM_OF_WORKS => 'Sample item',
            ExcelHeaders::NUMBER_OF_SHEET => '1',
            ExcelHeaders::PAPER_SIZE => 'A3',
            ExcelHeaders::DOCUMENT_NAME => 'Sample drawing',
            ExcelHeaders::DESCRIPTION => 'Sample job request. This Example sheet is not imported.',
            ExcelHeaders::KHI_REQUESTED_DATE => '2026-01-15',
            ExcelHeaders::KHI_IN_CHARGE => 'KHI',
            ExcelHeaders::KHI_DEADLINE => '2026-02-01',
            ExcelHeaders::KDT_DEADLINE => '2026-01-28',
            ExcelHeaders::EXPECTED_MANHOUR => '8',
            ExcelHeaders::KHI_PREP => '0',
        ];

        foreach ($headers as $index => $header) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($letter.'2', $sample[$header] ?? '');
        }
    }
}
