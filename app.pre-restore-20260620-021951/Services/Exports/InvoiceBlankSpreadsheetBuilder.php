<?php

declare(strict_types=1);

namespace App\Services\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class InvoiceBlankSpreadsheetBuilder
{
    private const LAST_COLUMN = 'L';

    private const SHADED = 'EEF1F6';

    private const BORDER = '8A9BAE';

    private const TEXT = '0D1E30';

    private const MUTED = '64748B';

    /** @var list<string> */
    private const TABLE_COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];

    /**
     * @param  array<string, mixed>  $payload  From InvoicePdfData::blankTemplate()
     */
    public function build(array $payload): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Invoice');

        $section = $payload['sections'][0] ?? [];
        $this->buildSection($sheet, $section);

        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4);

        return $spreadsheet;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function buildSection(Worksheet $sheet, array $section): void
    {
        $this->setColumnWidths($sheet);

        $row = 1;
        $row = $this->writeHeaderTop($sheet, $row, $section);
        $row = $this->writeHeaderBottomBar($sheet, $row);
        $row = $this->writeInfoRowOne($sheet, $row, $section);
        $row = $this->writeInfoRowTwo($sheet, $row, $section);
        $row = $this->writeItemsTable($sheet, $row, $section);
        $this->writePaymentFooter($sheet, $row, $section);
    }

    private function setColumnWidths(Worksheet $sheet): void
    {
        $widths = [
            'A' => 8,
            'B' => 18,
            'C' => 6,
            'D' => 7,
            'E' => 12,
            'F' => 6,
            'G' => 6,
            'H' => 6,
            'I' => 9,
            'J' => 11,
            'K' => 12,
            'L' => 13,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function writeHeaderTop(Worksheet $sheet, int $row, array $section): int
    {
        $logoRange = "A{$row}:C".($row + 1);
        $titleRange = "D{$row}:".self::LAST_COLUMN.($row + 1);

        $sheet->mergeCells($logoRange);
        $sheet->mergeCells($titleRange);

        $sheet->setCellValue("D{$row}", 'Invoice');

        $this->applyBorder($sheet, $logoRange);
        $this->applyBorder($sheet, $titleRange);
        $this->fillRange($sheet, $logoRange, self::SHADED);
        $this->fillRange($sheet, $titleRange, self::SHADED);

        $sheet->getStyle($titleRange)->applyFromArray([
            'font' => ['size' => 28, 'color' => ['rgb' => self::TEXT]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle($logoRange)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getRowDimension($row)->setRowHeight(28);
        $sheet->getRowDimension($row + 1)->setRowHeight(28);

        $this->embedLogo($sheet, $section['logoPath'] ?? null, "A{$row}");

        return $row + 2;
    }

    private function writeHeaderBottomBar(Worksheet $sheet, int $row): int
    {
        $range = "A{$row}:".self::LAST_COLUMN.$row;
        $sheet->mergeCells($range);
        $this->fillRange($sheet, $range, self::SHADED);
        $this->applyBorder($sheet, $range);
        $sheet->getRowDimension($row)->setRowHeight(8);

        return $row + 1;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function writeInfoRowOne(Worksheet $sheet, int $row, array $section): int
    {
        $labelRow = $row;
        $valueRow = $row + 1;

        $blocks = [
            ['A:D', 'Marking', $section['markingLabel'] ?? ''],
            ['E:H', 'Partai', $section['partaiLabel'] ?? ''],
            ['I:L', 'Tanggal Masuk Gudang China', $section['tanggalMasukLabel'] ?? ''],
        ];

        foreach ($blocks as [$cols, $label, $value]) {
            [$start, $end] = explode(':', $cols);
            $labelRange = "{$start}{$labelRow}:{$end}{$labelRow}";
            $valueRange = "{$start}{$valueRow}:{$end}{$valueRow}";

            $sheet->mergeCells($labelRange);
            $sheet->mergeCells($valueRange);
            $sheet->setCellValue("{$start}{$labelRow}", $label);
            $sheet->setCellValue("{$start}{$valueRow}", $value);

            $this->applyBorder($sheet, $labelRange);
            $this->applyBorder($sheet, $valueRange);

            $sheet->getStyle($labelRange)->applyFromArray([
                'font' => ['size' => 9, 'color' => ['rgb' => self::MUTED]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
            $sheet->getStyle($valueRange)->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => self::TEXT]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        $sheet->getRowDimension($labelRow)->setRowHeight(18);
        $sheet->getRowDimension($valueRow)->setRowHeight(22);

        return $valueRow + 1;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function writeInfoRowTwo(Worksheet $sheet, int $row, array $section): int
    {
        $deliverStart = $row;
        $deliverEnd = $row + 4;
        $metaStart = $row;
        $metaEnd = $row + 4;

        $deliverRange = "A{$deliverStart}:H{$deliverEnd}";
        $metaRange = "I{$metaStart}:L{$metaEnd}";

        $sheet->mergeCells($deliverRange);
        $sheet->mergeCells("I{$metaStart}:L".($metaStart + 1));
        $sheet->mergeCells("I".($metaStart + 2).":L".($metaStart + 2));
        $sheet->mergeCells("I".($metaStart + 3).":L".($metaStart + 3));
        $sheet->mergeCells("I".($metaStart + 4).":L".($metaStart + 4));

        $deliverText = implode("\n", [
            'Deliver to :',
            "Customer's Name: ".($section['customerName'] ?? ''),
            'Address: '.($section['customerAddress'] ?? ''),
            'Contact : '.($section['customerContact'] ?? ''),
            'Terms pembayaran : '.($section['paymentTerms'] ?? ''),
        ]);

        $sheet->setCellValue("A{$deliverStart}", $deliverText);
        $sheet->setCellValue("I{$metaStart}", 'No Index');
        $sheet->setCellValue('I'.($metaStart + 2), $section['noIndexLabel'] ?? '');
        $sheet->setCellValue('I'.($metaStart + 3), 'Invoice Date: ( invoice terbit)');
        $sheet->setCellValue('I'.($metaStart + 4), $section['invoiceDateLabel'] ?? '');

        $this->applyBorder($sheet, $deliverRange);
        $this->applyBorder($sheet, $metaRange);
        $this->fillRange($sheet, "I{$metaStart}:L{$metaStart}", self::SHADED);
        $this->fillRange($sheet, 'I'.($metaStart + 3).':L'.($metaStart + 3), self::SHADED);

        $sheet->getStyle($deliverRange)->applyFromArray([
            'font' => ['size' => 10, 'bold' => true, 'color' => ['rgb' => self::TEXT]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ]);

        foreach ([$metaStart, $metaStart + 3] as $headerRow) {
            $sheet->getStyle("I{$headerRow}:L{$headerRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => self::TEXT]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        foreach ([$metaStart + 2, $metaStart + 4] as $valueRow) {
            $sheet->getStyle("I{$valueRow}:L{$valueRow}")->applyFromArray([
                'font' => ['size' => 10, 'color' => ['rgb' => self::TEXT]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        $sheet->getRowDimension($deliverStart)->setRowHeight(16);
        for ($i = 1; $i <= 4; $i++) {
            $sheet->getRowDimension($deliverStart + $i)->setRowHeight(16);
        }

        return $deliverEnd + 1;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function writeItemsTable(Worksheet $sheet, int $row, array $section): int
    {
        $headerRow = $row;
        $headers = [
            'A' => "No\nIndex",
            'B' => 'Description Service',
            'C' => 'Coly',
            'D' => "CTN\nNo",
            'E' => "Harga\nBase",
            'F' => "P\n(cm)",
            'G' => "L\n(cm)",
            'H' => "T\n(cm)",
            'I' => 'Volume',
            'J' => "Total\nWeight",
            'K' => 'Notes',
            'L' => 'Amount',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $headerRange = "A{$headerRow}:".self::LAST_COLUMN.$headerRow;
        $this->applyBorder($sheet, $headerRange);
        $this->fillRange($sheet, $headerRange, self::SHADED);
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => self::TEXT]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $dataRow = $headerRow + 1;
        $sheet->mergeCells("A{$dataRow}:".self::LAST_COLUMN.$dataRow);
        $this->applyBorder($sheet, "A{$dataRow}:".self::LAST_COLUMN.$dataRow);
        $sheet->getRowDimension($dataRow)->setRowHeight(22);

        $summary = $section['tableSummary'] ?? [];
        $footerRows = [
            ['-', 'TOTAL', $summary['coly'] ?? '-', '-', '-', $summary['length'] ?? '-', $summary['width'] ?? '-', $summary['height'] ?? '-', $summary['volume'] ?? '-', $summary['weight'] ?? '-', '', ''],
            ['Perhitungan', $section['perhitunganLabel'] ?? ''],
            ['Total', $section['totalLabel'] ?? ''],
        ];

        $currentRow = $dataRow + 1;
        foreach ($footerRows as $index => $footer) {
            if ($index === 0) {
                foreach (self::TABLE_COLUMNS as $colIndex => $column) {
                    $sheet->setCellValue("{$column}{$currentRow}", $footer[$colIndex] ?? '');
                }
                $this->styleTableRow($sheet, $currentRow, true);
            } else {
                $label = $footer[0];
                $value = $footer[1] ?? '';
                $sheet->mergeCells("A{$currentRow}:K{$currentRow}");
                $sheet->setCellValue("A{$currentRow}", $label);
                $sheet->setCellValue("L{$currentRow}", $value);
                $this->styleSummaryFooterRow($sheet, $currentRow);
            }

            $currentRow++;
        }

        return $currentRow;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function writePaymentFooter(Worksheet $sheet, int $row, array $section): void
    {
        $headerRange = "A{$row}:C{$row}";
        $bodyRange = "D{$row}:".self::LAST_COLUMN.($row + 2);

        $sheet->mergeCells($headerRange);
        $sheet->mergeCells($bodyRange);

        $sheet->setCellValue("A{$row}", 'Payment Information');

        $bank = $section['invoicePaymentBank'] ?? '-';
        $accountName = $section['invoicePaymentAccountName'] ?? '-';
        $accountNumber = $section['invoicePaymentAccountNumber'] ?? '-';

        $paymentText = implode("\n", [
            "Bank: {$bank} | Nama: {$accountName} | No. Rek: {$accountNumber}",
            '• pembayaran di lakukan setelah barang bongkaran',
            '• komplain barang maximal 3 hari setelah di terima (tolong sertakan video kirim)',
        ]);

        $sheet->setCellValue("D{$row}", $paymentText);

        $this->applyBorder($sheet, "A{$row}:".self::LAST_COLUMN.($row + 2));
        $this->fillRange($sheet, $headerRange, self::SHADED);

        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => self::TEXT]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->getStyle($bodyRange)->applyFromArray([
            'font' => ['size' => 10, 'bold' => true, 'color' => ['rgb' => self::TEXT]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ]);

        $sheet->getRowDimension($row)->setRowHeight(22);
        $sheet->getRowDimension($row + 1)->setRowHeight(18);
        $sheet->getRowDimension($row + 2)->setRowHeight(18);
    }

    private function styleTableRow(Worksheet $sheet, int $row, bool $bold = false): void
    {
        $range = "A{$row}:".self::LAST_COLUMN.$row;
        $this->applyBorder($sheet, $range);

        $style = [
            'font' => [
                'size' => 9,
                'color' => ['rgb' => self::TEXT],
                'bold' => $bold,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ];

        if ($bold) {
            $this->fillRange($sheet, $range, self::SHADED);
        }

        $sheet->getStyle($range)->applyFromArray($style);
        $sheet->getStyle("B{$row}")->getAlignment()
            ->setHorizontal($bold ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("L{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function styleSummaryFooterRow(Worksheet $sheet, int $row): void
    {
        $labelRange = "A{$row}:K{$row}";
        $valueCell = "L{$row}";
        $fullRange = "A{$row}:".self::LAST_COLUMN.$row;

        $this->applyBorder($sheet, $fullRange);
        $this->fillRange($sheet, $labelRange, self::SHADED);

        $sheet->getStyle($labelRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => self::TEXT]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle($valueCell)->applyFromArray([
            'font' => ['size' => 9, 'color' => ['rgb' => self::TEXT]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function embedLogo(Worksheet $sheet, ?string $logoPath, string $cell): void
    {
        if ($logoPath === null || $logoPath === '' || ! is_file($logoPath)) {
            $sheet->setCellValue($cell, 'Logo');

            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setDescription('Company logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(56);
        $drawing->setCoordinates($cell);
        $drawing->setOffsetX(12);
        $drawing->setOffsetY(6);
        $drawing->setWorksheet($sheet);
    }

    private function applyBorder(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER],
                ],
            ],
        ]);
    }

    private function fillRange(Worksheet $sheet, string $range, string $color): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $color],
            ],
        ]);
    }
}
