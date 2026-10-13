<?php

declare(strict_types=1);

namespace App\Services\Exports;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class FinanceInvoiceListSpreadsheetBuilder
{
    private const HEADER_ROW = 1;

    private const FIRST_DATA_ROW = 2;

    private const LAST_COLUMN = 'P';

    private const HEADER_FILL = '0A2240';

    private const HEADER_FONT = 'FFFFFF';

    private const ACCENT_FILL = 'E88824';

    private const BORDER_COLOR = 'CBD5E1';

    private const RP_FORMAT = '[$Rp-421]#,##0_);[Red]\([$Rp-421]#,##0\)';

    private const DATE_PAYMENT_FORMAT = 'dd-mmm-yyyy';

    private const DATE_SHORT_FORMAT = 'dd-mmm-yyyy';

    /** @var list<string> */
    private const HEADERS = [
        'Invoice No',
        'No Index',
        'Marking',
        'Nama Customer',
        'Marketing',
        'Partai',
        'Loading Date',
        'Pengiriman',
        'Total',
        'Grand Total (Rp)',
        'Terbayar (Rp)',
        'Tanggal Pembayaran',
        'Sisa (Rp)',
        'Jatuh Tempo',
        'Tanggal Lunas',
        'Status',
    ];

    /** @var array<string, float> */
    private const COLUMN_WIDTHS = [
        'A' => 28,
        'B' => 16,
        'C' => 16,
        'D' => 16,
        'E' => 12,
        'F' => 10,
        'G' => 14,
        'H' => 22,
        'I' => 16,
        'J' => 18,
        'K' => 16,
        'L' => 18,
        'M' => 14,
        'N' => 14,
        'O' => 14,
        'P' => 18,
    ];

    /** Columns merged vertically per invoice block (template-style). */
    private const MERGED_COLUMNS = ['A', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'M', 'N', 'O', 'P'];

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function build(Worksheet $sheet, array $blocks): void
    {
        $sheet->setTitle('Invoice Pembayaran');
        $this->writeHeaders($sheet);
        $this->setColumnWidths($sheet);

        $row = self::FIRST_DATA_ROW;
        foreach ($blocks as $block) {
            $row = $this->writeInvoiceBlock($sheet, $block, $row);
        }

        if ($blocks === []) {
            $sheet->setCellValue('A'.self::FIRST_DATA_ROW, 'Tidak ada data invoice.');
            $sheet->mergeCells('A'.self::FIRST_DATA_ROW.':'.self::LAST_COLUMN.self::FIRST_DATA_ROW);
        }

        $sheet->freezePane('A'.self::FIRST_DATA_ROW);
    }

    private function writeHeaders(Worksheet $sheet): void
    {
        foreach (self::HEADERS as $index => $heading) {
            $column = $this->columnLetter($index);
            $sheet->setCellValue($column.self::HEADER_ROW, $heading);
        }

        $sheet->getStyle('A'.self::HEADER_ROW.':'.self::LAST_COLUMN.self::HEADER_ROW)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => self::HEADER_FONT],
            ],
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
                    'color' => ['rgb' => '0F2D52'],
                ],
            ],
        ]);

        // Accent underline under header (brand orange).
        $sheet->getStyle('A'.self::HEADER_ROW.':'.self::LAST_COLUMN.self::HEADER_ROW)
            ->getBorders()
            ->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM)
            ->getColor()
            ->setRGB(self::ACCENT_FILL);

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(28);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function writeInvoiceBlock(Worksheet $sheet, array $block, int $startRow): int
    {
        $shipments = array_values(is_array($block['shipments'] ?? null) ? $block['shipments'] : []);
        $payments = array_values(is_array($block['payments'] ?? null) ? $block['payments'] : []);
        $rowCount = max(1, count($shipments), count($payments));
        $endRow = $startRow + $rowCount - 1;

        for ($offset = 0; $offset < $rowCount; $offset++) {
            $row = $startRow + $offset;
            $shipment = $shipments[$offset] ?? null;
            $payment = $payments[$offset] ?? null;

            if ($offset === 0) {
                $sheet->setCellValue("A{$row}", (string) ($block['invoice_no'] ?? '-'));
                $sheet->setCellValue("C{$row}", (string) ($block['marking_label'] ?? '-'));
                $sheet->setCellValue("D{$row}", (string) ($block['customer_name'] ?? '-'));
                $sheet->setCellValue("E{$row}", (string) ($block['marketing_label'] ?? '-'));
                $sheet->setCellValue("F{$row}", (string) ($block['partai_label'] ?? '-'));
                $this->setDateCell($sheet, "G{$row}", $block['loading_date'] ?? null, self::DATE_SHORT_FORMAT);
                $sheet->setCellValue("H{$row}", (string) ($block['pengiriman'] ?? '-'));
                $sheet->setCellValue("J{$row}", "=SUM(I{$startRow}:I{$endRow})");
                $sheet->setCellValue("M{$row}", "=J{$startRow}-SUM(K{$startRow}:K{$endRow})");
                $sheet->setCellValue("N{$row}", (string) ($block['due_date_label'] ?? '—'));
                $this->setDateCell($sheet, "O{$row}", $block['tanggal_lunas'] ?? null, self::DATE_SHORT_FORMAT);
                if (($block['tanggal_lunas'] ?? null) === null) {
                    $sheet->setCellValue("O{$row}", (string) ($block['tanggal_lunas_label'] ?? '—'));
                }
                $sheet->setCellValue("P{$row}", (string) ($block['status_label'] ?? '-'));
            }

            $sheet->setCellValue("B{$row}", is_array($shipment) ? (string) ($shipment['no_index'] ?? '') : '');
            $sheet->setCellValue("I{$row}", is_array($shipment) ? (float) ($shipment['total'] ?? 0) : 0.0);
            $sheet->setCellValue("K{$row}", is_array($payment) ? (float) ($payment['amount'] ?? 0) : 0.0);

            if (is_array($payment)) {
                $this->setDateCell($sheet, "L{$row}", $payment['paid_at'] ?? null, self::DATE_PAYMENT_FORMAT);
            } else {
                $sheet->setCellValue("L{$row}", '—');
            }

            $this->styleDataRow($sheet, $row);
        }

        if ($rowCount > 1) {
            foreach (self::MERGED_COLUMNS as $column) {
                $sheet->mergeCells("{$column}{$startRow}:{$column}{$endRow}");
            }
        }

        $sheet->getStyle('A'.$startRow.':'.self::LAST_COLUMN.$endRow)->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $moneyRange = "I{$startRow}:K{$endRow}";
        $sheet->getStyle($moneyRange)->getNumberFormat()->setFormatCode(self::RP_FORMAT);
        $sheet->getStyle("J{$startRow}")->getNumberFormat()->setFormatCode(self::RP_FORMAT);
        $sheet->getStyle("M{$startRow}")->getNumberFormat()->setFormatCode(self::RP_FORMAT);

        $sheet->getStyle("J{$startRow}")->getFont()->setBold(true);
        $sheet->getStyle("M{$startRow}")->getFont()->setBold(true);

        return $endRow + 1;
    }

    private function styleDataRow(Worksheet $sheet, int $row): void
    {
        $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)->applyFromArray([
            'font' => ['size' => 10],
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
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFFFF'],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
    }

    private function setDateCell(Worksheet $sheet, string $coord, mixed $value, string $format): void
    {
        if ($value instanceof Carbon) {
            $sheet->setCellValue($coord, ExcelDate::PHPToExcel($value->copy()->startOfDay()));
            $sheet->getStyle($coord)->getNumberFormat()->setFormatCode($format);

            return;
        }

        if (is_string($value) && trim($value) !== '' && trim($value) !== '—') {
            try {
                $parsed = Carbon::parse($value)->startOfDay();
                $sheet->setCellValue($coord, ExcelDate::PHPToExcel($parsed));
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode($format);

                return;
            } catch (\Throwable) {
                $sheet->setCellValue($coord, $value);

                return;
            }
        }

        $sheet->setCellValue($coord, '—');
    }

    private function setColumnWidths(Worksheet $sheet): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function columnLetter(int $index): string
    {
        return chr(ord('A') + $index);
    }
}
