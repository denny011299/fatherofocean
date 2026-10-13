<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Support\ExcelDecimalFormat;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class OmsetSpreadsheetBuilder
{
    private const HEADER_ROW = 1;

    private const LAST_COLUMN = 'S';

    private const NAVY = '0F172A';

    private const STRIPE = 'F8FAFC';

    private const BORDER_GREY = 'CBD5E1';

    /** @var array<string, float> */
    private const COLUMN_WIDTHS = [
        'A' => 5.6640625,
        'B' => 15.109375,
        'C' => 17.5546875,
        'D' => 17.5546875,
        'E' => 12.88671875,
        'F' => 12.88671875,
        'G' => 20.21875,
        'H' => 16.5546875,
        'I' => 13.0,
        'J' => 13.0,
        'K' => 13.0,
        'L' => 16.109375,
        'M' => 13.0,
        'N' => 18.21875,
        'O' => 18.33203125,
        'P' => 11.33203125,
        'Q' => 13.0,
        'R' => 22.0,
        'S' => 14.0,
    ];

    /** @var list<string> */
    private const HEADERS = [
        'No',
        'Serial No.',
        'Marking',
        'Item',
        'CBM',
        'KG',
        'Harga Modal CBM',
        'Harga Modal Kg',
        'Total Modal CBM',
        'Total Modal Kg',
        'Keterangan',
        'Harga jual CBM',
        'Harga Jual Kg',
        'Total Jual',
        'Keuntungan',
        'Marketing',
        'Partai',
        'Catatan',
        'Status Bayar',
    ];

    /**
     * @param array{
     *     groups: list<array{
     *         marketing_name: string,
     *         rows: list<array<string, mixed>>,
     *     }>,
     *     partai_label: ?string,
     *     company_fixed_amount: float,
     *     company_name: string,
     * } $payload
     */
    public function build(Worksheet $sheet, array $payload): void
    {
        $sheet->setTitle('Worksheet');
        $this->writeHeaders($sheet);

        $currentRow = self::HEADER_ROW + 1;
        $lineNo = 1;
        $sectionCbmSumRows = [];
        $firstDataRow = $currentRow;
        $lastDataRow = $currentRow - 1;

        foreach ($payload['groups'] as $groupIndex => $group) {
            $sectionStartRow = $currentRow;
            $sectionDataStart = $currentRow;

            foreach ($group['rows'] as $row) {
                $this->writeDataRow($sheet, $currentRow, $lineNo, $row);
                $lineNo++;
                $lastDataRow = $currentRow;
                $currentRow++;
            }

            if ($group['rows'] === []) {
                continue;
            }

            $sectionDataEnd = $currentRow - 1;
            $summaryRow = $currentRow;
            $this->writeSectionSummaryRow($sheet, $summaryRow, $sectionDataStart, $sectionDataEnd);
            $sectionCbmSumRows[] = $summaryRow;
            $lastDataRow = $summaryRow;

            $sheet->mergeCells("P{$sectionStartRow}:P{$summaryRow}");
            $sheet->setCellValue("P{$sectionStartRow}", $group['marketing_name']);
            $this->styleMarketingCell($sheet, "P{$sectionStartRow}:P{$summaryRow}");

            $currentRow++;

            if ($groupIndex < count($payload['groups']) - 1) {
                $separatorRow = $currentRow;
                $sheet->mergeCells("B{$separatorRow}:P{$separatorRow}");
                $sheet->getStyle("B{$separatorRow}:P{$separatorRow}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::STRIPE],
                    ],
                ]);
                $sheet->getRowDimension($separatorRow)->setRowHeight(22.8);
                $currentRow++;
            }
        }

        if ($sectionCbmSumRows !== []) {
            $currentRow++;

            $grandRow = $currentRow;
            $cbmParts = array_map(static fn (int $row): string => "E{$row}", $sectionCbmSumRows);
            $cbmFormula = '=SUM('.implode('+', $cbmParts).')';

            $sheet->mergeCells("B{$grandRow}:C{$grandRow}");
            $sheet->setCellValue("B{$grandRow}", 'Total CBM');
            $sheet->setCellValue("E{$grandRow}", $cbmFormula);
            $sheet->mergeCells("G{$grandRow}:N{$grandRow}");
            $sheet->setCellValue("G{$grandRow}", 'Pendapatan Perusahaan');
            $fixedAmount = max(0, (float) $payload['company_fixed_amount']);
            $sheet->setCellValue("O{$grandRow}", "=SUM(E{$grandRow}*{$fixedAmount})");
            $sheet->setCellValue("P{$grandRow}", $payload['company_name']);
            $this->styleGrandTotalRow($sheet, $grandRow);
            $lastDataRow = $grandRow;
        }

        if ($lastDataRow >= $firstDataRow && $payload['partai_label'] !== null) {
            $sheet->mergeCells("Q{$firstDataRow}:Q{$lastDataRow}");
            $sheet->setCellValue("Q{$firstDataRow}", $payload['partai_label']);
            $this->stylePartaiCell($sheet, "Q{$firstDataRow}:Q{$lastDataRow}");
        }

        $tableEndRow = max(self::HEADER_ROW, $lastDataRow);
        $this->applyTableStyles($sheet, $firstDataRow, $tableEndRow);
    }

    private function writeHeaders(Worksheet $sheet): void
    {
        foreach (self::HEADERS as $index => $heading) {
            $column = chr(ord('A') + $index);
            $sheet->setCellValue("{$column}".self::HEADER_ROW, $heading);
        }

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(22.05);
        $sheet->getStyle('A'.self::HEADER_ROW.':'.self::LAST_COLUMN.self::HEADER_ROW)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::NAVY],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
    }

    /** @param array<string, mixed> $rowData */
    private function writeDataRow(Worksheet $sheet, int $row, int $lineNo, array $rowData): void
    {
        $sheet->setCellValue("A{$row}", $lineNo);
        $sheet->setCellValue("B{$row}", (string) ($rowData['serial_no'] ?? ''));
        $sheet->setCellValue("C{$row}", (string) ($rowData['marking'] ?? ''));

        if (($rowData['item'] ?? '') !== '') {
            $sheet->setCellValue("D{$row}", (string) $rowData['item']);
        }

        if ((float) ($rowData['cbm'] ?? 0) > 0) {
            $sheet->setCellValue("E{$row}", (float) $rowData['cbm']);
        }

        if ($rowData['kg'] !== null && (float) $rowData['kg'] > 0) {
            $sheet->setCellValue("F{$row}", (float) $rowData['kg']);
        }

        if ((float) ($rowData['modal_cbm_price'] ?? 0) > 0) {
            $sheet->setCellValue("G{$row}", (float) $rowData['modal_cbm_price']);
        }

        if ((float) ($rowData['modal_kg_price'] ?? 0) > 0) {
            $sheet->setCellValue("H{$row}", (float) $rowData['modal_kg_price']);
        }

        $sheet->setCellValue("I{$row}", "=SUM(E{$row}*G{$row})");

        if ((float) ($rowData['excess_kg'] ?? 0) > 0 && (float) ($rowData['modal_kg_price'] ?? 0) > 0) {
            $excess = self::formatFormulaNumber((float) $rowData['excess_kg']);
            $sheet->setCellValue("J{$row}", "=SUM(H{$row}*{$excess})");
        }

        $sheet->setCellValue("K{$row}", (string) ($rowData['keterangan'] ?? ''));

        if ((float) ($rowData['sell_cbm_price'] ?? 0) > 0) {
            $sheet->setCellValue("L{$row}", (float) $rowData['sell_cbm_price']);
        }

        if ((float) ($rowData['sell_kg_price'] ?? 0) > 0 && (float) ($rowData['excess_kg'] ?? 0) > 0) {
            $sheet->setCellValue("M{$row}", (float) $rowData['sell_kg_price']);
        }

        if ((float) ($rowData['excess_kg'] ?? 0) > 0 && (float) ($rowData['sell_kg_price'] ?? 0) > 0) {
            $excess = self::formatFormulaNumber((float) $rowData['excess_kg']);
            $sheet->setCellValue("N{$row}", "=SUM(L{$row}*E{$row})+SUM(M{$row}*{$excess})");
        } else {
            $sheet->setCellValue("N{$row}", "=SUM(L{$row}*E{$row})");
        }

        $sheet->setCellValue("O{$row}", "=SUM(N{$row}-I{$row})");

        $catatan = trim((string) ($rowData['catatan'] ?? ''));
        if ($catatan !== '') {
            $sheet->setCellValue("R{$row}", $catatan);
        }

        $sheet->setCellValue("S{$row}", (string) ($rowData['payment_status'] ?? 'Belum Bayar'));
    }

    private function writeSectionSummaryRow(
        Worksheet $sheet,
        int $row,
        int $dataStart,
        int $dataEnd,
    ): void {
        $sheet->mergeCells("B{$row}:C{$row}");
        $sheet->setCellValue("B{$row}", 'Total CBM');
        $sheet->setCellValue("E{$row}", "=SUM(E{$dataStart}:E{$dataEnd})");
        $sheet->mergeCells("G{$row}:N{$row}");
        $sheet->setCellValue("G{$row}", 'Total Terima income');
        $sheet->setCellValue("O{$row}", "=SUM(O{$dataStart}:O{$dataEnd})");

        $sheet->getStyle("B{$row}:O{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
        ]);
        $sheet->getStyle("B{$row}:C{$row}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F0FDF4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getStyle("G{$row}:N{$row}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F0FDF4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getStyle("E{$row}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::STRIPE],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22.8);
    }

    private function styleGrandTotalRow(Worksheet $sheet, int $row): void
    {
        $sheet->getStyle("B{$row}:P{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
        ]);
        $sheet->getStyle("B{$row}:C{$row}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F0FDF4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getStyle("G{$row}:N{$row}")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F0FDF4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22.8);
    }

    private function styleMarketingCell(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    private function stylePartaiCell(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    private function applyTableStyles(Worksheet $sheet, int $firstDataRow, int $lastRow): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        if ($lastRow < self::HEADER_ROW) {
            return;
        }

        $tableRange = 'A'.self::HEADER_ROW.':'.self::LAST_COLUMN.$lastRow;
        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
            'font' => ['size' => 11],
        ]);

        if ($firstDataRow <= $lastRow) {
            for ($row = $firstDataRow; $row <= $lastRow; $row++) {
                if (($row - $firstDataRow) % 2 === 0) {
                    $sheet->getStyle("A{$row}:".self::LAST_COLUMN.$row)->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => self::STRIPE],
                        ],
                    ]);
                }
            }

            $sheet->getStyle("A{$firstDataRow}:A{$lastRow}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'numberFormat' => ['formatCode' => ExcelDecimalFormat::COUNT],
            ]);

            $sheet->getStyle("B{$firstDataRow}:D{$lastRow}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            ExcelDecimalFormat::apply($sheet, "E{$firstDataRow}:E{$lastRow}", ExcelDecimalFormat::CBM);
            ExcelDecimalFormat::apply($sheet, "F{$firstDataRow}:F{$lastRow}", ExcelDecimalFormat::WEIGHT);

            $currencyColumns = ['G', 'H', 'I', 'J', 'L', 'M'];
            foreach ($currencyColumns as $column) {
                $sheet->getStyle("{$column}{$firstDataRow}:{$column}{$lastRow}")
                    ->applyFromArray([
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_RIGHT,
                            'vertical' => Alignment::VERTICAL_CENTER,
                        ],
                        'numberFormat' => ['formatCode' => ExcelDecimalFormat::RUPIAH],
                    ]);
            }

            $sheet->getStyle("K{$firstDataRow}:K{$lastRow}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            $sheet->getStyle("N{$firstDataRow}:N{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode(ExcelDecimalFormat::RUPIAH_TOTAL);

            $sheet->getStyle("O{$firstDataRow}:O{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode(ExcelDecimalFormat::RUPIAH_TOTAL);

            $sheet->getStyle("R{$firstDataRow}:R{$lastRow}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]);

            $sheet->getStyle("S{$firstDataRow}:S{$lastRow}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'font' => ['bold' => true],
            ]);

            $sheet->getStyle("E{$firstDataRow}:F{$lastRow}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }
    }

    private static function formatFormulaNumber(float $value): string
    {
        if (fmod($value, 1.0) === 0.0) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
