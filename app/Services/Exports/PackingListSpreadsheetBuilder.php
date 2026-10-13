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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
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

    private const PARTAI_COLUMN = 'A';

    private const MARKING_COLUMN = 'B';

    private const PHOTO_COLUMN = 'M';

    /** Last invoice data column before the photo column (used to copy border style). */
    private const INVOICE_BORDER_REFERENCE_COLUMN = 'K';

    /** Two-row merged header reference for the photo column title block. */
    private const INVOICE_PHOTO_HEADER_REFERENCE_CELL = 'K1';

    private const NAVY = '0A2240';

    private const BORDER_GREY = 'CBD5E1';

    /** Template default data row height (pt); used when a row has no product photo. */
    private const DEFAULT_DATA_ROW_HEIGHT_PT = 18;

    /**
     * Horizontal merges per invoice data row (description C:E, qty F:G, pcs H:I, amount K:L).
     *
     * @var list<array{0: string, 1: string}>
     */
    private const INVOICE_ROW_HORIZONTAL_MERGES = [
        ['C', 'E'],
        ['F', 'G'],
        ['H', 'I'],
        ['K', 'L'],
    ];

    /**
     * Horizontal merges per packing data row.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const PACKING_ROW_HORIZONTAL_MERGES = [
        ['D', 'E'],
        ['F', 'G'],
        ['H', 'I'],
        ['J', 'K'],
        ['L', 'M'],
    ];

    /**
     * @var list<array{
     *     partai: string,
     *     barang: Barang,
     *     product_name: string,
     *     ctns: int,
     *     pcs_per_box: int,
     *     total_pcs: int,
     *     unit_price: float,
     *     gross_kg: ?float,
     *     net_kg: ?float,
     *     cbm: ?float,
     *     photo_path: ?string,
     * }>
     */
    private array $lines = [];

    public function build(Container $container): Spreadsheet
    {
        return $this->buildMany(collect([$container]));
    }

    /** @param Collection<int, Container>|iterable<Container> $containers */
    public function buildMany(iterable $containers): Spreadsheet
    {
        $templatePath = $this->resolveTemplatePath();

        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($templatePath);

        $invoice = $spreadsheet->getSheetByName(self::INVOICE_SHEET);
        $packing = $spreadsheet->getSheetByName(self::PACKING_SHEET);

        if ($invoice === null || $packing === null) {
            throw new \RuntimeException('Packing list template is missing required worksheets.');
        }

        $this->lines = $this->collectLinesFromContainers($containers);

        $this->sanitizeWorkbook($spreadsheet);
        $this->removeDocumentHeader($invoice, $packing);
        $this->removeHsCodeColumn($invoice);
        $this->insertPartaiColumn($invoice, $packing);
        $this->configureInvoiceCurrencyHeaders($invoice);
        $this->configurePhotoColumn($invoice);

        $this->ensureDataRowCapacity($invoice, $packing, count($this->lines));

        $dataEndRow = $this->populateDataRows($invoice, $packing);
        $this->resetDataRowHeights($invoice, $dataEndRow);
        $this->applyDataAreaMerges($invoice, $packing, $dataEndRow);
        $this->populateTotals($invoice, $packing, $dataEndRow);
        $this->clearStaleDataRows($invoice, $packing, $dataEndRow);

        $dataFillEndRow = $this->resolveDataFillClearEndRow(count($this->lines));
        $this->clearDataRowFills($invoice, $dataFillEndRow);
        $this->clearDataRowFills($packing, $dataFillEndRow);
        $this->formatInvoicePhotoColumn($invoice, $dataEndRow);
        $this->formatInvoicePhotoColumnHeader($invoice);
        $this->applyPresentationStyles($invoice, $dataEndRow);
        $this->applyPresentationStyles($packing, $dataEndRow);

        return $spreadsheet;
    }

    /** @param Collection<int, Container>|iterable<Container> $containers */
    private function collectLinesFromContainers(iterable $containers): array
    {
        $lines = [];

        foreach ($containers as $container) {
            if (! $container->relationLoaded('barangs')) {
                $container->load([
                    'barangs.products' => static fn ($query) => $query->orderBy('sort_order')->with('parent'),
                    'barangs.customer',
                ]);
            }

            foreach ($this->collectLines($container) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<array{
     *     partai: string,
     *     barang: Barang,
     *     product_name: string,
     *     ctns: int,
     *     pcs_per_box: int,
     *     total_pcs: int,
     *     unit_price: float,
     *     gross_kg: ?float,
     *     net_kg: ?float,
     *     cbm: ?float,
     *     photo_path: ?string,
     * }>
     */
    private function collectLines(Container $container): array
    {
        $lines = [];
        $partai = trim((string) ($container->partai ?? $container->code ?? ''));

        foreach ($container->barangs as $barang) {
            if ($barang->products->isEmpty()) {
                $lines[] = [
                    'partai' => $partai,
                    'barang' => $barang,
                    'product_name' => (string) ($barang->material ?? $barang->marking ?? $barang->no_index ?? ''),
                    'ctns' => max(0, (int) $barang->ctns),
                    'pcs_per_box' => max(0, (int) $barang->pcs),
                    'total_pcs' => max(0, (int) $barang->ctns) * max(0, (int) $barang->pcs),
                    'unit_price' => 0.0,
                    'gross_kg' => (float) $barang->total_gross_weight,
                    'net_kg' => max(0, (float) $barang->total_gross_weight - (float) $barang->ctns),
                    'cbm' => (float) $barang->total_cbm,
                    'photo_path' => null,
                ];

                continue;
            }

            foreach (BarangProduct::orderedForForm($barang->products) as $product) {
                $isSubItem = $product->isSubItem();
                $master = $product->logisticsSource();
                $parentCtns = max(0, (int) $master->ctns);
                $pcsPerBox = max(0, (int) $product->pcs_per_box);

                if ($isSubItem) {
                    $ctns = 0;
                    $totalPcs = $pcsPerBox > 0 ? $pcsPerBox * $parentCtns : $parentCtns;
                    $grossKg = null;
                    $netKg = null;
                    $cbm = null;
                } else {
                    $ctns = $parentCtns;
                    $totalPcs = $pcsPerBox > 0 ? $pcsPerBox * $parentCtns : $parentCtns;
                    $grossKg = $master->lineGrossWeightKg();
                    $netKg = max(0.0, $master->lineGrossWeightKg() - $parentCtns);
                    $cbm = CbmFormat::truncate($master->resolvedLineCbm());
                }

                $lines[] = [
                    'partai' => $partai,
                    'barang' => $barang,
                    'product_name' => (string) ($product->product_name ?? ''),
                    'ctns' => $ctns,
                    'pcs_per_box' => $pcsPerBox,
                    'total_pcs' => $totalPcs,
                    'unit_price' => (float) $product->price_per_unit_rmb,
                    'gross_kg' => $grossKg,
                    'net_kg' => $netKg,
                    'cbm' => $cbm,
                    'photo_path' => $product->photo_path,
                ];
            }
        }

        return $lines;
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
            $totalPcs = (int) ($line['total_pcs'] ?? ($ctns * $pcsPerBox));
            $unitPrice = $line['unit_price'];
            $grossKg = $line['gross_kg'];
            $netKg = $line['net_kg'];
            $cbm = $line['cbm'];

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
            $this->clearPackingLogisticsCells($packing, $row);
            if ($grossKg !== null) {
                $packing->setCellValue("H{$row}", $grossKg);
                $packing->setCellValue("I{$row}", 'KGS');
            }
            if ($netKg !== null) {
                $packing->setCellValue("J{$row}", $netKg);
                $packing->setCellValue("K{$row}", 'KGS');
            }
            if ($cbm !== null) {
                $packing->setCellValue("L{$row}", $cbm);
                $packing->setCellValue("M{$row}", 'M3');
            }

            $row++;
        }

        if ($this->lines === []) {
            return self::DATA_START_ROW - 1;
        }

        return $row - 1;
    }

    private function applyDataAreaMerges(Worksheet $invoice, Worksheet $packing, int $dataEndRow): void
    {
        if ($dataEndRow < self::DATA_START_ROW) {
            return;
        }

        $unmergeThroughRow = max($dataEndRow, self::TEMPLATE_DATA_END_ROW);
        $this->unmergeCellsTouchingRows($invoice, self::DATA_START_ROW, $unmergeThroughRow);
        $this->unmergeCellsTouchingRows($packing, self::DATA_START_ROW, $unmergeThroughRow);

        $invoiceMerges = $this->resolveDataRowHorizontalMerges(
            $invoice,
            self::DATA_START_ROW,
            self::TEMPLATE_DATA_END_ROW,
            self::INVOICE_ROW_HORIZONTAL_MERGES,
            self::PHOTO_COLUMN,
        );
        $packingMerges = $this->resolveDataRowHorizontalMerges(
            $packing,
            self::DATA_START_ROW,
            self::TEMPLATE_DATA_END_ROW,
            self::PACKING_ROW_HORIZONTAL_MERGES,
        );

        for ($row = self::DATA_START_ROW; $row <= $dataEndRow; $row++) {
            foreach ($invoiceMerges as [$startCol, $endCol]) {
                $invoice->mergeCells("{$startCol}{$row}:{$endCol}{$row}");
            }

            foreach ($packingMerges as [$startCol, $endCol]) {
                $packing->mergeCells("{$startCol}{$row}:{$endCol}{$row}");
            }
        }

        $this->applyMarkingColumnVerticalMerges($invoice, $dataEndRow, self::PARTAI_COLUMN);
        $this->applyMarkingColumnVerticalMerges($invoice, $dataEndRow, self::MARKING_COLUMN);
        $this->applyMarkingColumnVerticalMerges($packing, $dataEndRow, self::PARTAI_COLUMN);
        $this->applyMarkingColumnVerticalMerges($packing, $dataEndRow, self::MARKING_COLUMN);
    }

    private function resetDataRowHeights(Worksheet $invoice, int $dataEndRow): void
    {
        if ($dataEndRow < self::DATA_START_ROW) {
            return;
        }

        for ($row = self::DATA_START_ROW; $row <= $dataEndRow; $row++) {
            $photoCell = self::PHOTO_COLUMN . $row;
            $hasPhoto = false;

            foreach ($invoice->getDrawingCollection() as $drawing) {
                if ($drawing instanceof Drawing && $drawing->getCoordinates() === $photoCell) {
                    $hasPhoto = true;

                    break;
                }
            }

            if (! $hasPhoto) {
                $invoice->getRowDimension($row)->setRowHeight(self::DEFAULT_DATA_ROW_HEIGHT_PT);
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $canonicalMerges
     * @return list<array{0: string, 1: string}>
     */
    private function resolveDataRowHorizontalMerges(
        Worksheet $sheet,
        int $dataStartRow,
        int $templateDataEndRow,
        array $canonicalMerges,
        ?string $excludeColumn = null,
    ): array {
        $merges = [];

        foreach ($canonicalMerges as [$startCol, $endCol]) {
            $merges["{$startCol}:{$endCol}"] = [$startCol, $endCol];
        }

        foreach ($sheet->getMergeCells() as $range) {
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches)) {
                continue;
            }

            $startCol = $matches[1];
            $startRow = (int) $matches[2];
            $endCol = $matches[3];
            $endRow = (int) $matches[4];

            if ($startRow !== $endRow || $startRow < $dataStartRow || $startRow > $templateDataEndRow) {
                continue;
            }

            if ($excludeColumn !== null && $this->mergeRangeTouchesColumn($startCol, $endCol, $excludeColumn)) {
                continue;
            }

            $merges["{$startCol}:{$endCol}"] = [$startCol, $endCol];
        }

        return array_values($merges);
    }

    private function mergeRangeTouchesColumn(string $startCol, string $endCol, string $column): bool
    {
        $startIndex = Coordinate::columnIndexFromString($startCol);
        $endIndex = Coordinate::columnIndexFromString($endCol);
        $columnIndex = Coordinate::columnIndexFromString($column);

        return $columnIndex >= $startIndex && $columnIndex <= $endIndex;
    }

    private function applyMarkingColumnVerticalMerges(Worksheet $sheet, int $dataEndRow, string $column): void
    {
        $groupStart = self::DATA_START_ROW;

        for ($row = self::DATA_START_ROW; $row <= $dataEndRow + 1; $row++) {
            $startsNewGroup = $row > $dataEndRow;

            if (! $startsNewGroup) {
                $markCellValue = $sheet->getCell("{$column}{$row}")->getValue();
                $startsNewGroup = $markCellValue !== null && $markCellValue !== '' && $row > $groupStart;
            }

            if (! $startsNewGroup) {
                continue;
            }

            $groupEnd = $row - 1;

            if ($groupEnd > $groupStart) {
                $range = "{$column}{$groupStart}:{$column}{$groupEnd}";
                $sheet->mergeCells($range);
                $sheet->getStyle($range)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $groupStart = $row;
        }
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
        $packing->setCellValue("H{$totalRow}", "=SUM(H{$first}:H{$dataEndRow})");
        $packing->setCellValue("I{$totalRow}", 'KGS');
        $packing->setCellValue("J{$totalRow}", "=SUM(J{$first}:J{$dataEndRow})");
        $packing->setCellValue("K{$totalRow}", 'KGS');
        $packing->setCellValue("L{$totalRow}", "=SUM(L{$first}:L{$dataEndRow})");
        $packing->setCellValue("M{$totalRow}", 'M3');

        $this->applyTotalRowHorizontalMerges($invoice, $packing, $totalRow);
    }

    private function clearPackingLogisticsCells(Worksheet $packing, int $row): void
    {
        foreach (['H', 'I', 'J', 'K', 'L', 'M'] as $column) {
            $packing->getCell("{$column}{$row}")->setValueExplicit(null, DataType::TYPE_NULL);
        }
    }

    private function applyTotalRowHorizontalMerges(Worksheet $invoice, Worksheet $packing, int $totalRow): void
    {
        foreach (self::INVOICE_ROW_HORIZONTAL_MERGES as [$startCol, $endCol]) {
            $invoice->mergeCells("{$startCol}{$totalRow}:{$endCol}{$totalRow}");
        }

        foreach (self::PACKING_ROW_HORIZONTAL_MERGES as [$startCol, $endCol]) {
            $packing->mergeCells("{$startCol}{$totalRow}:{$endCol}{$totalRow}");
        }
    }

    private function clearStaleDataRows(Worksheet $invoice, Worksheet $packing, int $dataEndRow): void
    {
        // Keep total (dataEndRow + 1) and CNF (dataEndRow + 2) rows; clear template leftovers below.
        $clearFrom = max($dataEndRow + 3, self::DATA_START_ROW);
        $clearTo = self::TEMPLATE_CNF_ROW;

        $this->unmergeCellsTouchingRows($invoice, $clearFrom, self::TEMPLATE_DATA_END_ROW);
        $this->unmergeCellsTouchingRows($packing, $clearFrom, self::TEMPLATE_DATA_END_ROW);

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
        $columnWidths = [
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

        $headerRange = 'A' . self::COLUMN_HEADER_ROW . ':M' . self::COLUMN_SUBHEADER_ROW;
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
            $dataRange = 'A' . self::DATA_START_ROW . ':M' . $dataEndRow;
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
            $sheet->getStyle("A{$totalRow}:M{$cnfRow}")->applyFromArray([
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

            $sheet->getStyle('A' . self::COLUMN_HEADER_ROW . ':M' . $cnfRow)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => self::BORDER_GREY],
                    ],
                ],
            ]);
        }
    }

    private function applyDecimalColumnFormats(Worksheet $sheet, int $dataEndRow): void
    {
        $start = self::DATA_START_ROW;
        $end = $dataEndRow;
        $totalRow = $dataEndRow + 1;

        if ($sheet->getTitle() === self::INVOICE_SHEET) {
            ExcelDecimalFormat::apply($sheet, "F{$start}:F{$end}", ExcelDecimalFormat::COUNT);
            ExcelDecimalFormat::apply($sheet, "H{$start}:H{$end}", ExcelDecimalFormat::COUNT);
            ExcelDecimalFormat::apply($sheet, "J{$start}:K{$end}", ExcelDecimalFormat::DECIMAL_2);
            ExcelDecimalFormat::apply($sheet, "F{$totalRow}", ExcelDecimalFormat::COUNT);
            ExcelDecimalFormat::apply($sheet, "H{$totalRow}", ExcelDecimalFormat::COUNT);
            ExcelDecimalFormat::apply($sheet, "K{$totalRow}", ExcelDecimalFormat::DECIMAL_2);

            return;
        }

        if ($sheet->getTitle() === self::PACKING_SHEET) {
            ExcelDecimalFormat::apply($sheet, "D{$start}:D{$end}", ExcelDecimalFormat::COUNT);
            ExcelDecimalFormat::apply($sheet, "F{$start}:F{$end}", ExcelDecimalFormat::COUNT);
            ExcelDecimalFormat::apply($sheet, "H{$start}:H{$end}", ExcelDecimalFormat::WEIGHT);
            ExcelDecimalFormat::apply($sheet, "J{$start}:J{$end}", ExcelDecimalFormat::WEIGHT);
            ExcelDecimalFormat::apply($sheet, "L{$start}:L{$end}", ExcelDecimalFormat::CBM);
            ExcelDecimalFormat::apply($sheet, "D{$totalRow}", ExcelDecimalFormat::COUNT);
            ExcelDecimalFormat::apply($sheet, "F{$totalRow}", ExcelDecimalFormat::COUNT);
            ExcelDecimalFormat::apply($sheet, "H{$totalRow}", ExcelDecimalFormat::WEIGHT);
            ExcelDecimalFormat::apply($sheet, "J{$totalRow}", ExcelDecimalFormat::WEIGHT);
            ExcelDecimalFormat::apply($sheet, "L{$totalRow}", ExcelDecimalFormat::CBM);
        }
    }

    private function unmergeCellsTouchingRows(Worksheet $sheet, int $fromRow, int $toRow): void
    {
        foreach ($sheet->getMergeCells() as $range) {
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches)) {
                continue;
            }

            $startRow = (int) $matches[2];
            $endRow = (int) $matches[4];

            if ($endRow >= $fromRow && $startRow <= $toRow) {
                $sheet->unmergeCells($range);
            }
        }
    }

    private function resolveTemplatePath(): string
    {
        $configured = config('packing_list_export.template');
        $default = resource_path('excel/packing-list-commercial-invoice-template.xls');

        $candidates = [];
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        if (is_string($default) && $default !== '') {
            $candidates[] = $default;
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        $checked = implode(', ', array_map(
            static fn (string $path): string => "[{$path}]",
            $candidates,
        ));

        throw new \RuntimeException(
            'Packing list Excel template not found. Upload packing-list-commercial-invoice-template.xls to '
            . 'resources/excel/ on the server, deploy config/packing_list_export.php, then run: php artisan config:clear. '
            . 'Checked paths: '.$checked
        );
    }
}
