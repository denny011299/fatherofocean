<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Support\ExcelDecimalFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class SuratJalanRekapanSpreadsheetBuilder
{
    private const LAST_COLUMN = 'O';

    private const TITLE_ROW = 1;

    private const SUBTITLE_ROW = 2;

    private const HEADER_ROW = 3;

    private const FIRST_DATA_ROW = 4;

    private const HEADER_FILL = 'E8EEF4';

    private const TITLE_FILL = '0A2240';

    private const TOTAL_FILL = 'F5F7FA';

    private const BORDER_COLOR = '334155';

    private const MIN_DATA_ROW_HEIGHT = 32.0;

    private const MAX_DATA_ROW_HEIGHT = 88.0;

    private const LINE_HEIGHT_PT = 14.0;

    private const DATA_FONT_SIZE = 10;

    /** @var list<string> */
    private const HEADERS = [
        'No Urut',
        'Marking',
        'Item',
        'Coly',
        'Total Coly',
        'CBM',
        'KG',
        'Total CBM',
        'Total KG',
        'No Polisi',
        'Nama Supir',
        'No HP Supir',
        'Telp',
        'Alamat',
        'Notes',
    ];

    /** @var array<string, float> */
    private const COLUMN_WIDTHS = [
        'A' => 10,
        'B' => 15,
        'C' => 28,
        'D' => 9,
        'E' => 8,
        'F' => 9,
        'G' => 9,
        'H' => 10,
        'I' => 10,
        'J' => 13,
        'K' => 15,
        'L' => 13,
        'M' => 14,
        'N' => 34,
        'O' => 18,
    ];

    /** @var array<string, int> */
    private const WRAP_CHARS = [
        'A' => 10,
        'B' => 14,
        'C' => 30,
        'N' => 36,
        'O' => 18,
    ];

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function build(array $rows, ?string $containerLabel = null): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekapan SJ');

        $this->configurePage($sheet);
        $this->setColumnWidths($sheet);
        $this->writeTitleBlock($sheet, $containerLabel);
        $this->writeHeaders($sheet);

        if ($rows === []) {
            $this->writeEmptyState($sheet);

            return $spreadsheet;
        }

        $lastRow = $this->writeRows($sheet, $rows);
        $this->applyTableBorders($sheet, self::HEADER_ROW, $lastRow);

        return $spreadsheet;
    }

    private function configurePage(Worksheet $sheet): void
    {
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $sheet->getPageMargins()
            ->setTop(0.45)
            ->setRight(0.45)
            ->setLeft(0.45)
            ->setBottom(0.45);
    }

    private function setColumnWidths(Worksheet $sheet): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeTitleBlock(Worksheet $sheet, ?string $containerLabel): void
    {
        $title = 'Rekapan Surat Jalan';
        if ($containerLabel !== null && trim($containerLabel) !== '') {
            $title .= ' — '.trim($containerLabel);
        }

        $sheet->mergeCells('A'.self::TITLE_ROW.':'.self::LAST_COLUMN.self::TITLE_ROW);
        $sheet->setCellValue('A'.self::TITLE_ROW, $title);
        $sheet->getStyle('A'.self::TITLE_ROW)->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::TITLE_FILL],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'indent' => 1,
            ],
        ]);
        $sheet->getRowDimension(self::TITLE_ROW)->setRowHeight(30);

        $sheet->mergeCells('A'.self::SUBTITLE_ROW.':'.self::LAST_COLUMN.self::SUBTITLE_ROW);
        $subtitle = $containerLabel !== null && trim($containerLabel) !== ''
            ? 'Ringkasan pengiriman per marking · '.trim($containerLabel)
            : 'Ringkasan pengiriman per marking';
        $sheet->setCellValue('A'.self::SUBTITLE_ROW, $subtitle);
        $sheet->getStyle('A'.self::SUBTITLE_ROW)->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '475569']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8FAFC'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
                'indent' => 1,
            ],
        ]);
        $sheet->getRowDimension(self::SUBTITLE_ROW)->setRowHeight(22);
    }

    private function writeHeaders(Worksheet $sheet): void
    {
        foreach (self::HEADERS as $index => $heading) {
            $column = chr(ord('A') + $index);
            $sheet->setCellValue($column.self::HEADER_ROW, $heading);
        }

        $sheet->getStyle('A'.self::HEADER_ROW.':'.self::LAST_COLUMN.self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '0F172A']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::HEADER_FILL],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_COLOR],
                ],
            ],
        ]);
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(28);
    }

    private function writeEmptyState(Worksheet $sheet): void
    {
        $row = self::FIRST_DATA_ROW;
        $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
        $sheet->setCellValue('A'.$row, 'Belum ada data surat jalan.');
        $sheet->getStyle('A'.$row)->applyFromArray([
            'font' => ['size' => self::DATA_FONT_SIZE],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_COLOR],
                ],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(self::MIN_DATA_ROW_HEIGHT);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeRows(Worksheet $sheet, array $rows): int
    {
        $row = self::FIRST_DATA_ROW;
        /** @var list<array{row: int, data: array<string, mixed>}> */
        $sheetRows = [];

        foreach ($rows as $rowData) {
            $perKirimanColy = trim((string) ($rowData['coly'] ?? ''));
            if ($perKirimanColy === '' && (int) ($rowData['coly_raw'] ?? 0) > 0) {
                $perKirimanColy = (string) ($rowData['coly_raw'] ?? '');
            }

            $displayTotalColy = trim((string) ($rowData['total_coly'] ?? ''));
            if ($displayTotalColy === '') {
                $displayTotalColy = $perKirimanColy;
            }

            $this->setTextCell($sheet, 'A'.$row, $this->dashIfEmpty((string) ($rowData['no_urut'] ?? '')));
            $this->setTextCell($sheet, 'B'.$row, $this->dashIfEmpty((string) ($rowData['marking'] ?? '')));
            $this->setTextCell($sheet, 'C'.$row, (string) ($rowData['item'] ?? ''));
            $this->setTextCell($sheet, 'D'.$row, $this->dashIfEmpty($perKirimanColy));
            $this->setTextCell($sheet, 'E'.$row, $this->dashIfEmpty($displayTotalColy));
            $this->writeNumericOrDash($sheet, 'F'.$row, (string) ($rowData['cbm'] ?? ''), (float) ($rowData['cbm_raw'] ?? 0));
            $this->writeNumericOrDash($sheet, 'G'.$row, (string) ($rowData['kg'] ?? ''), (float) ($rowData['kg_raw'] ?? 0));
            $this->writeNumericOrDash($sheet, 'H'.$row, (string) ($rowData['total_cbm'] ?? ''), null, true);
            $this->writeNumericOrDash($sheet, 'I'.$row, (string) ($rowData['total_kg'] ?? ''), null, true);
            $this->setTextCell($sheet, 'J'.$row, (string) ($rowData['truck_plate'] ?? ''));
            $this->setTextCell($sheet, 'K'.$row, (string) ($rowData['driver_name'] ?? ''));
            $this->setTextCell($sheet, 'L'.$row, (string) ($rowData['driver_phone'] ?? ''));
            $this->setTextCell($sheet, 'M'.$row, (string) ($rowData['telp'] ?? ''));
            $this->setTextCell($sheet, 'N'.$row, (string) ($rowData['alamat'] ?? ''));
            $this->setTextCell($sheet, 'O'.$row, (string) ($rowData['notes'] ?? ''));

            $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)->applyFromArray([
                'font' => ['size' => self::DATA_FONT_SIZE],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_TOP,
                    'wrapText' => true,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => self::BORDER_COLOR],
                    ],
                    'top' => [
                        'borderStyle' => ! empty($rowData['show_group'])
                            ? Border::BORDER_MEDIUM
                            : Border::BORDER_THIN,
                        'color' => ['rgb' => self::BORDER_COLOR],
                    ],
                ],
            ]);

            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B'.$row)->applyFromArray([
                'font' => ['bold' => true, 'size' => self::DATA_FONT_SIZE],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_TOP,
                    'wrapText' => true,
                ],
            ]);
            $sheet->getStyle('D'.$row.':E'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F'.$row.':G'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('H'.$row.':I'.$row)->applyFromArray([
                'font' => ['bold' => true, 'size' => self::DATA_FONT_SIZE],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => self::TOTAL_FILL],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
            $sheet->getStyle('J'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('L'.$row.':M'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            ExcelDecimalFormat::apply($sheet, 'F'.$row, ExcelDecimalFormat::DECIMAL_3);
            ExcelDecimalFormat::apply($sheet, 'G'.$row, ExcelDecimalFormat::WEIGHT);
            ExcelDecimalFormat::apply($sheet, 'H'.$row, ExcelDecimalFormat::DECIMAL_3);
            ExcelDecimalFormat::apply($sheet, 'I'.$row, ExcelDecimalFormat::WEIGHT);

            $sheet->getRowDimension($row)->setRowHeight($this->estimateRowHeight($rowData));

            $sheetRows[] = ['row' => $row, 'data' => $rowData];
            $row++;
        }

        $this->applyMergeGroups($sheet, $sheetRows);
        $this->applyMarkingMerges($sheet, $sheetRows);

        return $row - 1;
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    private function estimateRowHeight(array $rowData): float
    {
        $lineCounts = [
            $this->estimateWrappedLines((string) ($rowData['item'] ?? ''), self::WRAP_CHARS['C']),
            $this->estimateWrappedLines((string) ($rowData['alamat'] ?? ''), self::WRAP_CHARS['N']),
            $this->estimateWrappedLines((string) ($rowData['marking'] ?? ''), self::WRAP_CHARS['B']),
            $this->estimateWrappedLines((string) ($rowData['notes'] ?? ''), self::WRAP_CHARS['O']),
        ];

        $lines = max(1, ...$lineCounts);
        $height = ($lines * self::LINE_HEIGHT_PT) + 12;

        return min(self::MAX_DATA_ROW_HEIGHT, max(self::MIN_DATA_ROW_HEIGHT, $height));
    }

    private function estimateWrappedLines(string $text, int $charsPerLine): int
    {
        $text = trim($text);
        if ($text === '') {
            return 1;
        }

        $charsPerLine = max(8, $charsPerLine);
        $lines = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $segment) {
            $length = mb_strlen($segment);
            $lines += max(1, (int) ceil($length / $charsPerLine));
        }

        return max(1, $lines);
    }

    /**
     * @param  list<array{row: int, data: array<string, mixed>}>  $sheetRows
     */
    private function applyMergeGroups(Worksheet $sheet, array $sheetRows): void
    {
        if ($sheetRows === []) {
            return;
        }

        $index = 0;
        $count = count($sheetRows);
        while ($index < $count) {
            $mergeKey = $this->mergeKeyForEntry($sheetRows[$index]);
            $endIndex = $index + 1;
            while ($endIndex < $count && $this->mergeKeyForEntry($sheetRows[$endIndex]) === $mergeKey) {
                $endIndex++;
            }

            $block = array_slice($sheetRows, $index, $endIndex - $index);
            if (count($block) > 1 && $mergeKey !== '') {
                $this->mergeBlock($sheet, $block);
            }

            $index = $endIndex;
        }
    }

    /**
     * @param  list<array{row: int, data: array<string, mixed>}>  $sheetRows
     */
    private function applyMarkingMerges(Worksheet $sheet, array $sheetRows): void
    {
        if ($sheetRows === []) {
            return;
        }

        $index = 0;
        $count = count($sheetRows);
        while ($index < $count) {
            $marking = mb_strtolower(trim((string) ($sheetRows[$index]['data']['marking'] ?? '')));
            $endIndex = $index + 1;
            while ($endIndex < $count) {
                $nextMarking = mb_strtolower(trim((string) ($sheetRows[$endIndex]['data']['marking'] ?? '')));
                if ($nextMarking !== $marking || $marking === '') {
                    break;
                }
                $endIndex++;
            }

            $startRow = $sheetRows[$index]['row'];
            $endRow = $sheetRows[$endIndex - 1]['row'];
            if ($startRow !== $endRow) {
                $sheet->mergeCells('B'.$startRow.':B'.$endRow);
                $sheet->getStyle('B'.$startRow.':B'.$endRow)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $index = $endIndex;
        }
    }

    /**
     * @param  list<array{row: int, data: array<string, mixed>}>  $block
     */
    private function mergeBlock(Worksheet $sheet, array $block): void
    {
        $startRow = $block[0]['row'];
        $endRow = $block[count($block) - 1]['row'];
        $firstData = $block[0]['data'];

        $totalColy = trim((string) ($firstData['total_coly'] ?? ''));
        if ($totalColy === '') {
            $sum = 0;
            foreach ($block as $entry) {
                $sum += (int) ($entry['data']['coly_raw'] ?? 0);
            }
            $totalColy = $sum > 0 ? (string) $sum : '';
        }

        $alamat = (string) ($firstData['alamat'] ?? '');
        $telp = (string) ($firstData['telp'] ?? '');

        for ($offset = 1; $offset < count($block); $offset++) {
            $rowNumber = $block[$offset]['row'];
            $this->setTextCell($sheet, 'E'.$rowNumber, '');
            $this->setTextCell($sheet, 'M'.$rowNumber, '');
            $this->setTextCell($sheet, 'N'.$rowNumber, '');
        }

        $this->setTextCell($sheet, 'E'.$startRow, $this->dashIfEmpty($totalColy));
        $this->setTextCell($sheet, 'M'.$startRow, $telp);
        $this->setTextCell($sheet, 'N'.$startRow, $alamat);

        if ($startRow !== $endRow) {
            foreach (['E', 'H', 'I', 'M', 'N'] as $column) {
                $sheet->mergeCells($column.$startRow.':'.$column.$endRow);
                $sheet->getStyle($column.$startRow.':'.$column.$endRow)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);
            }

            $mergedHeight = 0.0;
            for ($rowNumber = $startRow; $rowNumber <= $endRow; $rowNumber++) {
                $mergedHeight += $sheet->getRowDimension($rowNumber)->getRowHeight();
            }
            $perRow = $mergedHeight / count($block);
            foreach ($block as $entry) {
                $sheet->getRowDimension($entry['row'])->setRowHeight(max(self::MIN_DATA_ROW_HEIGHT, $perRow));
            }
        }

        $sheet->getStyle('E'.$startRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('H'.$startRow.':I'.$startRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('M'.$startRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * @param  array{row: int, data: array<string, mixed>}  $entry
     */
    private function mergeKeyForEntry(array $entry): string
    {
        return trim((string) ($entry['data']['merge_group_key'] ?? ''));
    }

    private function writeNumericOrDash(
        Worksheet $sheet,
        string $cell,
        string $formatted,
        ?float $rawValue = null,
        bool $isTotal = false,
    ): void {
        if ($formatted === '') {
            $sheet->setCellValue($cell, '');

            return;
        }

        if ($rawValue !== null && $rawValue > 0) {
            $sheet->setCellValue($cell, $rawValue);

            return;
        }

        $normalized = str_replace(',', '.', trim($formatted));
        if (is_numeric($normalized)) {
            $sheet->setCellValue($cell, (float) $normalized);

            return;
        }

        $sheet->setCellValue($cell, $isTotal ? $formatted : $this->dashIfEmpty($formatted));
    }

    private function dashIfEmpty(string $value): string
    {
        return trim($value) !== '' ? trim($value) : '-';
    }

    private function setTextCell(Worksheet $sheet, string $coordinate, string $value): void
    {
        $sheet->getCell($coordinate)->setValueExplicit($value, DataType::TYPE_STRING);
    }

    private function applyTableBorders(Worksheet $sheet, int $firstRow, int $lastRow): void
    {
        if ($lastRow < $firstRow) {
            return;
        }

        $sheet->getStyle('A'.$firstRow.':'.self::LAST_COLUMN.$lastRow)->applyFromArray([
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => self::BORDER_COLOR],
                ],
            ],
        ]);
    }
}
