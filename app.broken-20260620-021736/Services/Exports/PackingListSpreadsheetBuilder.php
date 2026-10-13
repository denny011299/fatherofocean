<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Exports\Concerns\EmbedsProductPhotoInSheet;
use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\Container;
use App\Support\CbmFormat;
use App\Support\ExcelDecimalFormat;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class PackingListSpreadsheetBuilder
{
    use EmbedsProductPhotoInSheet;

    private const INVOICE_SHEET = '发票';

    private const PACKING_SHEET = '箱单';

    /** Rows 1–12 in the template: company block, document title, consignee / invoice metadata. */
    private const HEADER_ROWS_COUNT = 12;

    private const DATA_START_ROW = 4;

    private const TEMPLATE_DATA_END_ROW = 91;

    private const TEMPLATE_TOTAL_ROW = 92;

    private const TEMPLATE_CNF_ROW = 93;

    private const COLUMN_HEADER_ROW = 1;

    private const COLUMN_SUBHEADER_ROW = 2;

    private const PHOTO_COLUMN = 'L';

    /** Last invoice data column before the photo column (used to copy border style). */
    private const INVOICE_BORDER_REFERENCE_COLUMN = 'J';

    /** Two-row merged header reference for the photo column title block. */
    private const INVOICE_PHOTO_HEADER_REFERENCE_CELL = 'A1';

    /** Template default data row height (pt); used when a row has no product photo. */
    private const DEFAULT_DATA_ROW_HEIGHT_PT = 15.75;

    /**
     * @var list<array{
     *     partai: string,
     *     barang: Barang,
     *     product_name: string,
     *     ctns: int,
     *     pcs_per_box: int,
     *     unit_price: float,
     *     gross_kg: float,
     *     net_kg: float,
     *     length_cm: ?float,
     *     width_cm: ?float,
     *     height_cm: ?float,
     *     cbm: float,
     *     photo_path: ?string,
     * }>
     */
    private array $lines = [];

    public function build(Container $container): Spreadsheet
    {
        $templatePath = $this->resolveTemplatePath();

        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($templatePath);

        $invoice = $spreadsheet->getSheetByName(self::INVOICE_SHEET);
        $packing = $spreadsheet->getSheetByName(self::PACKING_SHEET);

        if ($invoice === null || $packing === null) {
            throw new \RuntimeException('Packing list template is missing required worksheets.');
        }

        $this->lines = $this->collectLines($container);

        $this->sanitizeWorkbook($spreadsheet);
        $this->removeDocumentHeader($invoice, $packing);
        $this->removeHsCodeColumn($invoice);
        $this->configurePhotoColumn($invoice);

        $this->ensureDataRowCapacity($invoice, $packing, count($this->lines));

        $dataEndRow = $this->populateDataRows($invoice, $packing);
        $this->formatInvoicePhotoColumn($invoice, $dataEndRow);
        $this->populateTotals($invoice, $packing, $dataEndRow);
        $this->clearStaleDataRows($invoice, $packing, $dataEndRow);

        $dataFillEndRow = $this->resolveDataFillClearEndRow(count($this->lines));
        $this->clearDataRowFills($invoice, $dataFillEndRow);
        $this->clearDataRowFills($packing, $dataFillEndRow);

        return $spreadsheet;
    }

    /**
     * @return list<array{
     *     barang: Barang,
     *     product_name: string,
     *     ctns: int,
     *     pcs_per_box: int,
     *     unit_price: float,
     *     gross_kg: float,
     *     net_kg: float,
     *     length_cm: ?float,
     *     width_cm: ?float,
     *     height_cm: ?float,
     *     cbm: float,
     *     photo_path: ?string,
     * }>
     */
    private function collectLines(Container $container): array
    {
        $lines = [];
        $partai = trim((string) ($container->partai ?? $container->code ?? ''));

        foreach ($container->barangs as $barang) {
            if ($barang->products->isEmpty()) {
                $displayCbm = CbmFormat::truncate((float) $barang->total_cbm);
                $lines[] = [
                    'partai' => $partai,
                    'barang' => $barang,
                    'product_name' => (string) ($barang->material ?? $barang->marking ?? $barang->no_index ?? ''),
                    'ctns' => max(0, (int) $barang->ctns),
                    'pcs_per_box' => max(0, (int) $barang->pcs),
                    'unit_price' => 0.0,
                    'gross_kg' => (float) $barang->total_gross_weight,
                    'net_kg' => max(0, (float) $barang->total_gross_weight - (float) $barang->ctns),
                    'length_cm' => null,
                    'width_cm' => null,
                    'height_cm' => null,
                    'cbm' => $displayCbm,
                    'photo_path' => null,
                ];

                continue;
            }

            foreach ($barang->products as $product) {
                $master = $product->logisticsSource();
                $ctns = max(0, (int) $master->ctns);
                $logistics = $this->resolveProductLogistics($product);

                $lines[] = [
                    'partai' => $partai,
                    'barang' => $barang,
                    'product_name' => (string) ($product->product_name ?? ''),
                    'ctns' => $ctns,
                    'pcs_per_box' => max(0, (int) $product->pcs_per_box),
                    'unit_price' => (float) $product->price_per_unit_rmb,
                    'gross_kg' => $master->lineGrossWeightKg(),
                    'net_kg' => max(0, $master->lineGrossWeightKg() - $ctns),
                    'length_cm' => $logistics['length_cm'],
                    'width_cm' => $logistics['width_cm'],
                    'height_cm' => $logistics['height_cm'],
                    'cbm' => $logistics['cbm'],
                    'photo_path' => $product->photo_path,
                ];
            }
        }

        return $lines;
    }

    /**
     * @return array{
     *     length_cm: ?float,
     *     width_cm: ?float,
     *     height_cm: ?float,
     *     cbm: float,
     * }
     */
    private function resolveProductLogistics(BarangProduct $product): array
    {
        $logistics = $product->logisticsSource();
        $length = (float) $logistics->length_cm;
        $width = (float) $logistics->width_cm;
        $height = (float) $logistics->height_cm;

        $cbm = 0.0;
        if (! $product->isSubItem()) {
            $ctns = max(0, (int) $product->ctns);
            $rawCbm = ($length * $width * $height * $ctns) / 1_000_000;
            $storedCbm = (float) $logistics->volume_cbm;

            if ($storedCbm > 0) {
                $cbm = CbmFormat::truncate($storedCbm);
            } elseif ($rawCbm > 0) {
                $cbm = CbmFormat::truncate($rawCbm);
            }
        }

        return [
            'length_cm' => $length > 0 ? $length : null,
            'width_cm' => $width > 0 ? $width : null,
            'height_cm' => $height > 0 ? $height : null,
            'cbm' => $cbm,
        ];
    }

    private function ensureDataRowCapacity(Worksheet $invoice, Worksheet $packing, int $lineCount): void
    {
        $capacity = self::TEMPLATE_DATA_END_ROW - self::DATA_START_ROW + 1;

        if ($lineCount <= $capacity) {
            return;
        }

        $extraRows = $lineCount - $capacity;
        $invoice->insertNewRowBefore(self::TEMPLATE_TOTAL_ROW, $extraRows);
        $packing->insertNewRowBefore(self::TEMPLATE_TOTAL_ROW, $extraRows);
    }

    private function sanitizeWorkbook(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $this->removeWorksheetDrawings($sheet);
        }
    }

    private function resolveDataFillClearEndRow(int $lineCount): int
    {
        $capacity = self::TEMPLATE_DATA_END_ROW - self::DATA_START_ROW + 1;
        $extraRows = max(0, $lineCount - $capacity);

        return self::TEMPLATE_DATA_END_ROW + $extraRows;
    }

    private function removeWorksheetDrawings(Worksheet $sheet): void
    {
        foreach ($sheet->getDrawingCollection() as $drawing) {
            if ($drawing instanceof Drawing) {
                $drawing->setPath('');
                $drawing->setWorksheet(null);
            }
        }
    }

    private function clearDataRowFills(Worksheet $sheet, int $endRow): void
    {
        $highestColumn = $sheet->getHighestColumn();
        $lastColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        for ($row = self::DATA_START_ROW; $row <= $endRow; $row++) {
            for ($col = 1; $col <= $lastColumnIndex; $col++) {
                $coordinate = Coordinate::stringFromColumnIndex($col) . $row;
                $fill = $sheet->getStyle($coordinate)->getFill();

                if ($fill->getFillType() !== Fill::FILL_SOLID) {
                    continue;
                }

                $rgb = strtoupper($fill->getStartColor()->getRGB());
                if ($this->shouldClearDataRowFill($rgb)) {
                    $fill->setFillType(Fill::FILL_NONE);
                }
            }
        }
    }

    private function shouldClearDataRowFill(string $rgb): bool
    {
        return ! $this->isPreservedFillColor($rgb);
    }

    /**
     * Plain white / grey header tones kept; template highlight colors (green, yellow, red, etc.) cleared.
     */
    private function isPreservedFillColor(string $rgb): bool
    {
        if ($rgb === '' || $rgb === 'FFFFFF') {
            return true;
        }

        return in_array($rgb, [
            'D9D9D9',
            'D0CECE',
            'F2F2F2',
            'E7E6E6',
            'CCCCCC',
            'B7B7B7',
            'C0C0C0',
            '808080',
            'BFBFBF',
            'D9E1F2',
            'EDEDED',
            'F7F7F7',
        ], true);
    }

    private function removeDocumentHeader(Worksheet $invoice, Worksheet $packing): void
    {
        $invoice->removeRow(1, self::HEADER_ROWS_COUNT);
        $packing->removeRow(1, self::HEADER_ROWS_COUNT);
    }

    private function removeHsCodeColumn(Worksheet $invoice): void
    {
        $headerRow = self::COLUMN_HEADER_ROW;
        $subheaderRow = self::COLUMN_SUBHEADER_ROW;
        $legacyPhotoColumn = 'L';

        $invoice->setCellValue($legacyPhotoColumn . $headerRow, '');
        $invoice->setCellValue($legacyPhotoColumn . $subheaderRow, '');
        $invoice->removeColumn($legacyPhotoColumn);
    }

    private function insertPartaiColumn(Worksheet $invoice, Worksheet $packing): void
    {
        $invoice->insertNewColumnBefore(self::PARTAI_COLUMN);
        $packing->insertNewColumnBefore(self::PARTAI_COLUMN);

        $partaiHeader = "Partai\n批次";

        $invoice->setCellValue(self::PARTAI_COLUMN . self::COLUMN_HEADER_ROW, $partaiHeader);
        $invoice->setCellValue(self::PARTAI_COLUMN . self::COLUMN_SUBHEADER_ROW, '');
        $invoice->mergeCells(self::PARTAI_COLUMN . self::COLUMN_HEADER_ROW . ':' . self::PARTAI_COLUMN . self::COLUMN_SUBHEADER_ROW);

        $packing->setCellValue(self::PARTAI_COLUMN . self::COLUMN_HEADER_ROW, $partaiHeader);
        $packing->setCellValue(self::PARTAI_COLUMN . self::COLUMN_SUBHEADER_ROW, '');
        $packing->mergeCells(self::PARTAI_COLUMN . self::COLUMN_HEADER_ROW . ':' . self::PARTAI_COLUMN . self::COLUMN_SUBHEADER_ROW);
    }

    private function insertPackingDimensionColumns(Worksheet $packing): void
    {
        $packing->insertNewColumnBefore('L', 3);

        foreach ([
            'L' => "P (cm)\n长度",
            'M' => "L (cm)\n宽度",
            'N' => "T (cm)\n高度",
        ] as $column => $label) {
            $packing->setCellValue($column . self::COLUMN_HEADER_ROW, $label);
            $packing->setCellValue($column . self::COLUMN_SUBHEADER_ROW, '');
            $packing->mergeCells($column . self::COLUMN_HEADER_ROW . ':' . $column . self::COLUMN_SUBHEADER_ROW);
        }
    }

    private function configureInvoiceCurrencyHeaders(Worksheet $invoice): void
    {
        $unitPriceHeader = (string) config(
            'packing_list_export.unit_price_column_header',
            "Unit Price\n(RMB)",
        );
        $amountHeader = (string) config(
            'packing_list_export.amount_column_header',
            "Amount\n(RMB)",
        );

        $invoice->setCellValue('J' . self::COLUMN_HEADER_ROW, $unitPriceHeader);
        $invoice->setCellValue('J' . self::COLUMN_SUBHEADER_ROW, '');
        $invoice->mergeCells('J' . self::COLUMN_HEADER_ROW . ':J' . self::COLUMN_SUBHEADER_ROW);

        $invoice->setCellValue('K' . self::COLUMN_HEADER_ROW, $amountHeader);
        $invoice->setCellValue('K' . self::COLUMN_SUBHEADER_ROW, '');
        $invoice->mergeCells('K' . self::COLUMN_HEADER_ROW . ':K' . self::COLUMN_SUBHEADER_ROW);
    }

    private function configurePhotoColumn(Worksheet $invoice): void
    {
        $width = (float) config('packing_list_export.photo_column_width', 14);
        $invoice->getColumnDimension(self::PHOTO_COLUMN)->setWidth($width);
    }

    private function populateDataRows(Worksheet $invoice, Worksheet $packing): int
    {
        $row = self::DATA_START_ROW;
        $previousPartai = null;
        $previousMarking = null;

        foreach ($this->lines as $line) {
            $barang = $line['barang'];
            $partai = (string) ($line['partai'] ?? '');
            $marking = $barang->marking ?: 'N/M';
            $ctns = $line['ctns'];
            $pcsPerBox = $line['pcs_per_box'];
            $totalPcs = $ctns * $pcsPerBox;
            $unitPrice = $line['unit_price'];
            $grossKg = $line['gross_kg'];
            $netKg = $line['net_kg'];
            $cbm = $line['cbm'];
            $lengthCm = $line['length_cm'];
            $widthCm = $line['width_cm'];
            $heightCm = $line['height_cm'];

            $partaiCell = ($partai !== '' && $partai !== $previousPartai) ? $partai : '';
            $marksCell = ($marking !== $previousMarking) ? $marking : '';
            $previousPartai = $partai !== '' ? $partai : $previousPartai;
            $previousMarking = $marking;

            $invoice->setCellValue(self::PARTAI_COLUMN . "{$row}", $partaiCell);
            $invoice->setCellValue(self::MARKING_COLUMN . "{$row}", $marksCell);
            $invoice->setCellValue("C{$row}", $line['product_name']);
            $invoice->setCellValue("F{$row}", $ctns);
            $invoice->setCellValue("G{$row}", 'PKGS');
            $invoice->setCellValue("H{$row}", $totalPcs);
            $invoice->setCellValue("I{$row}", 'PCS');
            $invoice->setCellValue("J{$row}", $unitPrice);
            $invoice->setCellValue("K{$row}", $unitPrice * $totalPcs);

            $this->embedProductPhotoInSheet(
                $invoice,
                $line['photo_path'],
                self::PHOTO_COLUMN . $row,
                $row,
                (float) config('packing_list_export.photo_column_width', 14),
                (float) config('packing_list_export.photo_row_height_pt', 72),
                (int) config('packing_list_export.photo_image_height_px', 88),
                self::INVOICE_BORDER_REFERENCE_COLUMN,
            );

            $packing->setCellValue(self::PARTAI_COLUMN . "{$row}", $partaiCell);
            $packing->setCellValue(self::MARKING_COLUMN . "{$row}", $marksCell);
            $packing->setCellValue("C{$row}", $line['product_name']);
            $packing->setCellValue("D{$row}", $ctns);
            $packing->setCellValue("E{$row}", 'PKGS');
            $packing->setCellValue("F{$row}", $totalPcs);
            $packing->setCellValue("G{$row}", 'PCS');
            $packing->setCellValue("H{$row}", $grossKg);
            $packing->setCellValue("I{$row}", 'KGS');
            $packing->setCellValue("J{$row}", $netKg);
            $packing->setCellValue("K{$row}", 'KGS');

            if ($lengthCm !== null) {
                $packing->setCellValue("L{$row}", $lengthCm);
            }

            if ($widthCm !== null) {
                $packing->setCellValue("M{$row}", $widthCm);
            }

            if ($heightCm !== null) {
                $packing->setCellValue("N{$row}", $heightCm);
            }

            if ($cbm > 0) {
                $packing->setCellValue(self::PACKING_CBM_COLUMN . "{$row}", $cbm);
            }

            $packing->setCellValue(self::PACKING_LAST_COLUMN . "{$row}", 'M3');

            $row++;
        }

        if ($this->lines === []) {
            return self::DATA_START_ROW - 1;
        }

        return $row - 1;
    }

    private function formatInvoicePhotoColumn(Worksheet $invoice, int $dataEndRow): void
    {
        if ($dataEndRow < self::DATA_START_ROW) {
            return;
        }

        for ($row = self::DATA_START_ROW; $row <= $dataEndRow; $row++) {
            $cell = self::PHOTO_COLUMN . $row;
            $this->formatPhotoCell($invoice, $cell, $row, self::INVOICE_BORDER_REFERENCE_COLUMN);
            $invoice->setCellValue($cell, null);
        }
    }

    private function formatInvoicePhotoColumnHeader(Worksheet $invoice): void
    {
        $headerText = (string) config('packing_list_export.photo_column_header', "Photo\n品图");

        $this->formatPhotoColumnHeader(
            $invoice,
            self::PHOTO_COLUMN,
            self::COLUMN_HEADER_ROW,
            self::COLUMN_SUBHEADER_ROW,
            self::INVOICE_BORDER_REFERENCE_COLUMN,
            self::INVOICE_PHOTO_HEADER_REFERENCE_CELL,
            $headerText,
        );
    }

    private function populateTotals(Worksheet $invoice, Worksheet $packing, int $dataEndRow): void
    {
        if ($dataEndRow < self::DATA_START_ROW) {
            $invoice->setCellValue('C' . self::TEMPLATE_TOTAL_ROW, 'Total Quantities');
            $packing->setCellValue('C' . self::TEMPLATE_TOTAL_ROW, 'Total');

            return;
        }

        $totalRow = $dataEndRow + 1;
        $cnfRow = $dataEndRow + 2;
        $first = self::DATA_START_ROW;

        $invoice->setCellValue("C{$totalRow}", 'Total Quantities');
        $invoice->setCellValue("F{$totalRow}", "=SUM(F{$first}:F{$dataEndRow})");
        $invoice->setCellValue("G{$totalRow}", 'PKGS');
        $invoice->setCellValue("H{$totalRow}", "=SUM(H{$first}:H{$dataEndRow})");
        $invoice->setCellValue("I{$totalRow}", 'PCS');
        $invoice->setCellValue("J{$totalRow}", 'Total Amount');
        $invoice->setCellValue("K{$totalRow}", "=SUM(K{$first}:K{$dataEndRow})");

        $invoice->setCellValue("C{$cnfRow}", 'Total CNF');
        $invoice->setCellValue("K{$cnfRow}", "=K{$totalRow}");

        $packing->setCellValue("C{$totalRow}", 'Total');
        $packing->setCellValue("D{$totalRow}", "=SUM(D{$first}:D{$dataEndRow})");
        $packing->setCellValue("E{$totalRow}", 'PKGS');
        $packing->setCellValue("F{$totalRow}", "=SUM(F{$first}:F{$dataEndRow})");
        $packing->setCellValue("G{$totalRow}", 'PCS');
        $packing->setCellValue("H{$totalRow}", "=SUM(H{$first}:H{$dataEndRow})");
        $packing->setCellValue("I{$totalRow}", 'KGS');
        $packing->setCellValue("J{$totalRow}", "=SUM(J{$first}:J{$dataEndRow})");
        $packing->setCellValue("K{$totalRow}", 'KGS');
        $packing->setCellValue(self::PACKING_CBM_COLUMN . "{$totalRow}", '=SUM(' . self::PACKING_CBM_COLUMN . "{$first}:" . self::PACKING_CBM_COLUMN . "{$dataEndRow})");
        $packing->setCellValue(self::PACKING_LAST_COLUMN . "{$totalRow}", 'M3');
    }

    private function clearStaleDataRows(Worksheet $invoice, Worksheet $packing, int $dataEndRow): void
    {
        $clearFrom = max($dataEndRow + 1, self::DATA_START_ROW);
        $clearTo = self::TEMPLATE_CNF_ROW;

        for ($row = $clearFrom; $row <= self::TEMPLATE_DATA_END_ROW; $row++) {
            foreach (range('A', 'M') as $col) {
                $invoice->setCellValue("{$col}{$row}", null);
                $packing->setCellValue("{$col}{$row}", null);
            }
        }

        $oldTotalStart = self::TEMPLATE_TOTAL_ROW;
        for ($row = $oldTotalStart; $row <= $clearTo; $row++) {
            if ($row > $dataEndRow + 2) {
                foreach (range('A', 'M') as $col) {
                    $invoice->setCellValue("{$col}{$row}", null);
                    $packing->setCellValue("{$col}{$row}", null);
                }
            }
        }
    }

    private function applyPresentationStyles(Worksheet $sheet, int $dataEndRow): void
    {
        $isPackingSheet = $sheet->getTitle() === self::PACKING_SHEET;
        $lastColumn = $isPackingSheet ? self::PACKING_LAST_COLUMN : 'M';

        $columnWidths = $isPackingSheet
            ? [
                'A' => 11,
                'B' => 15,
                'C' => 34,
                'D' => 5,
                'E' => 5,
                'F' => 9,
                'G' => 7,
                'H' => 11,
                'I' => 7,
                'J' => 12,
                'K' => 7,
                'L' => 8,
                'M' => 8,
                'N' => 8,
                'O' => 10,
                'P' => 7,
            ]
            : [
                'A' => 11,
                'B' => 15,
                'C' => 34,
                'D' => 5,
                'E' => 5,
                'F' => 9,
                'G' => 7,
                'H' => 11,
                'I' => 7,
                'J' => 12,
                'K' => 14,
                'L' => 7,
                'M' => 18,
            ];

        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $headerRange = 'A' . self::COLUMN_HEADER_ROW . ':' . $lastColumn . self::COLUMN_SUBHEADER_ROW;
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
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
        $sheet->getRowDimension(self::COLUMN_HEADER_ROW)->setRowHeight(28);
        $sheet->getRowDimension(self::COLUMN_SUBHEADER_ROW)->setRowHeight(24);

        if ($dataEndRow >= self::DATA_START_ROW) {
            $dataRange = 'A' . self::DATA_START_ROW . ':' . $lastColumn . $dataEndRow;
            $sheet->getStyle($dataRange)->applyFromArray([
                'font' => ['size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]);

            $sheet->getStyle('C' . self::DATA_START_ROW . ':C' . $dataEndRow)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $this->applyDecimalColumnFormats($sheet, $dataEndRow);

            $totalRow = $dataEndRow + 1;
            $cnfRow = $dataEndRow + 2;
            $sheet->getStyle("A{$totalRow}:{$lastColumn}{$cnfRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F1F5F9'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            $sheet->getStyle('A' . self::COLUMN_HEADER_ROW . ':' . $lastColumn . $cnfRow)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => self::BORDER_GREY],
                    ],
                ],
            ]);
        }
    }
}
