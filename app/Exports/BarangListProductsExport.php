<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\MasterPricingItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Borders;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BarangListProductsExport implements WithEvents
{
    private const HEADER_ROW_1 = 1;

    private const HEADER_ROW_2 = 2;

    private const DATA_START_ROW = 3;

    private const LAST_COLUMN = 'R';

    private const RED = 'FF0000';

    private const BORDER_GREY = '000000';

    private const KATEGORI_COLOR_UMUM = 'C6EFCE';

    private const KATEGORI_COLOR_LARTAS_RINGAN = 'FFF2CC';

    private const KATEGORI_COLOR_LARTAS_BERAT = 'FFC7CE';

    private const KATEGORI_COLOR_GARMENT_TEXTILE = 'E1BEE7';

    private const KATEGORI_COLOR_TAS_SEPATU = 'BDD7EE';

    private const PHOTO_COLUMN_WIDTH = 14;

    private const PHOTO_ROW_HEIGHT_PT = 52;

    private const PHOTO_CELL_PADDING_PX = 2;

    /** Template sample rows may carry diagonal borders / fills — clear through this row. */
    private const TEMPLATE_STYLE_CLEAR_END_ROW = 200;

    /** Merged per coly (parent + sub rows): logistics only on parent row. */
    private const COLY_MERGE_COLUMNS = [
        'E',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
    ];

    /** Merged per barang (all product rows). */
    private const BARANG_MERGE_COLUMNS = [
        'A',
        'B',
        'F',
        'P',
        'Q',
        'R',
    ];

    /**
     * @var list<array{
     *     barang: Barang,
     *     product: ?BarangProduct,
     *     kind: string,
     *     merge_group: string,
     * }>
     */
    private array $lines = [];

    /**
     * @param  EloquentCollection<int, Barang>  $barangs
     */
    public function __construct(EloquentCollection $barangs)
    {
        foreach ($barangs as $barang) {
            if ($barang->products->isEmpty()) {
                $this->lines[] = [
                    'barang' => $barang,
                    'product' => null,
                    'kind' => 'product',
                    'merge_group' => $this->resolveMergeGroup($barang, null, 'product'),
                ];
                $this->appendPricingLines($barang);

                continue;
            }

            foreach ($barang->products as $product) {
                if (! $product->isSubItem() && $this->parentHasSubItems($barang, $product)) {
                    continue;
                }

                $this->lines[] = [
                    'barang' => $barang,
                    'product' => $product,
                    'kind' => 'product',
                    'merge_group' => $this->resolveMergeGroup($barang, $product, 'product'),
                ];
            }

            $this->appendPricingLines($barang);
        }
    }

    private function appendPricingLines(Barang $barang): void
    {
        $pricing = \App\Support\BarangShippingPricing::calculate($barang);

        if ($pricing['method'] !== \App\Support\BarangShippingPricing::METHOD_SPLIT) {
            return;
        }

        $this->lines[] = [
            'barang' => $barang,
            'product' => null,
            'kind' => 'split_kg',
            'merge_group' => $this->resolveMergeGroup($barang, null, 'split_kg'),
        ];
    }

    private function resolveMergeGroup(Barang $barang, ?BarangProduct $product, string $kind): string
    {
        if ($kind === 'split_kg') {
            return $barang->id.':split';
        }

        if ($product === null) {
            return $barang->id.':summary';
        }

        $parentId = $product->isSubItem()
            ? (int) $product->parent_id
            : (int) $product->getKey();

        return $barang->id.':coly:'.$parentId;
    }

    public static function filename(): string
    {
        return 'daftar-kirim-produk-'.now()->format('Y-m-d_His').'.xlsx';
    }

    public static function resolveBarangNotes(?string $notes): string
    {
        return trim((string) $notes);
    }

    public static function resolveKategoriLabel(?string $cbmType): string
    {
        $raw = trim((string) $cbmType);
        if ($raw === '') {
            return '';
        }

        if (ctype_digit($raw)) {
            $item = MasterPricingItem::query()->find((int) $raw);

            return trim((string) ($item?->name ?? ''));
        }

        return $raw;
    }

    public static function resolveKategoriFillColor(string $kategoriLabel): ?string
    {
        $key = mb_strtolower(trim($kategoriLabel));
        if ($key === '') {
            return null;
        }

        if (str_contains($key, 'lartas berat')) {
            return self::KATEGORI_COLOR_LARTAS_BERAT;
        }

        if (str_contains($key, 'lartas ringan') || str_contains($key, 'lartas umum')) {
            return self::KATEGORI_COLOR_LARTAS_RINGAN;
        }

        if (str_contains($key, 'garment') && str_contains($key, 'textile')) {
            return self::KATEGORI_COLOR_GARMENT_TEXTILE;
        }

        if (str_contains($key, 'tas sepatu') || str_contains($key, 'sepatu')) {
            return self::KATEGORI_COLOR_TAS_SEPATU;
        }

        if ($key === 'umum' || str_ends_with($key, ' umum')) {
            return self::KATEGORI_COLOR_UMUM;
        }

        if (str_contains($key, 'lartas')) {
            return self::KATEGORI_COLOR_LARTAS_RINGAN;
        }

        return null;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $delegate = $event->sheet->getDelegate();
                $spreadsheet = $delegate->getParent();

                if (! $spreadsheet instanceof Spreadsheet) {
                    return;
                }

                $built = $this->buildSpreadsheet();
                $builtSheet = $built->getSheetByName('Daftar Kirim') ?? $built->getActiveSheet();
                $index = $spreadsheet->getIndex($delegate);

                $spreadsheet->removeSheetByIndex($index);
                $spreadsheet->addExternalSheet($builtSheet, $index);
                $spreadsheet->setActiveSheetIndex($index);
            },
        ];
    }

    private function buildSpreadsheet(): Spreadsheet
    {
        $templatePath = resource_path('excel/daftar-kirim-export-template.xlsx');
        $spreadsheet = is_file($templatePath)
            ? IOFactory::load($templatePath)
            : new Spreadsheet();

        $sheet = $spreadsheet->getSheetByName('Daftar Kirim') ?? $spreadsheet->getActiveSheet();
        $sheet->setTitle('Daftar Kirim');

        $this->prepareTemplateSheet($sheet);
        $this->fillDataRows($sheet);
        $lastRow = $this->lastDataRow($sheet);
        $this->applyStyles($sheet, $lastRow);
        $this->applyKategoriColumnFill($sheet, $lastRow);

        return $spreadsheet;
    }

    private function prepareTemplateSheet(Worksheet $sheet): void
    {
        if (! is_file(resource_path('excel/daftar-kirim-export-template.xlsx'))) {
            $this->writeHeaders($sheet);
        }

        foreach (array_keys($sheet->getMergeCells()) as $mergeRange) {
            if ($this->mergeRangeTouchesDataRows($mergeRange)) {
                $sheet->unmergeCells($mergeRange);
            }
        }

        $highestRow = max($sheet->getHighestRow(), self::DATA_START_ROW);
        $clearThrough = max($highestRow, self::TEMPLATE_STYLE_CLEAR_END_ROW);
        for ($row = self::DATA_START_ROW; $row <= $clearThrough; $row++) {
            $this->resetDataRowStyle($sheet, $row);
        }
    }

    private function resetDataRowStyle(Worksheet $sheet, int $row): void
    {
        foreach (range('A', self::LAST_COLUMN) as $column) {
            $coordinate = $column.$row;
            $sheet->setCellValue($coordinate, null);
            $sheet->getStyle($coordinate)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFFFFF'],
                ],
                'borders' => [
                    'diagonalDirection' => Borders::DIAGONAL_NONE,
                    'diagonal' => [
                        'borderStyle' => Border::BORDER_NONE,
                    ],
                ],
            ]);
        }

        $sheet->getRowDimension($row)->setRowHeight(-1);
    }

    private function mergeRangeTouchesDataRows(string $mergeRange): bool
    {
        if (! preg_match('/[A-Z]+(\d+)(?::[A-Z]+(\d+))?/', $mergeRange, $matches) || ! isset($matches[1])) {
            return false;
        }

        $startRow = (int) $matches[1];
        $endRow = isset($matches[2]) ? (int) $matches[2] : $startRow;

        return $endRow >= self::DATA_START_ROW;
    }

    private function writeHeaders(Worksheet $sheet): void
    {
        $headerRow1 = self::HEADER_ROW_1;
        $headerRow2 = self::HEADER_ROW_2;

        $singleHeaders = [
            'A' => 'No urut',
            'B' => 'marking',
            'C' => 'Foto',
            'D' => 'Item',
            'E' => 'Total coly',
            'F' => 'Coly Final',
            'G' => 'PCS/BOX',
            'H' => 'Total PCS',
            'L' => 'CBM',
            'M' => 'Toatal CBM',
            'N' => 'Berat',
            'O' => 'Total Berat',
            'P' => 'Notes',
            'Q' => 'Kategori',
            'R' => 'Tanggal',
        ];

        foreach ($singleHeaders as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow1}", $label);
            $sheet->mergeCells("{$col}{$headerRow1}:{$col}{$headerRow2}");
        }

        $sheet->setCellValue("I{$headerRow1}", 'Dimensi');
        $sheet->mergeCells("I{$headerRow1}:K{$headerRow1}");

        foreach (['I' => 'P', 'J' => 'L', 'K' => 'T'] as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow2}", $label);
        }
    }

    private function fillDataRows(Worksheet $sheet): void
    {
        $row = self::DATA_START_ROW;
        /** @var array<string, list<int>> $colyRowGroups */
        $colyRowGroups = [];
        /** @var array<int, list<int>> $barangRowGroups */
        $barangRowGroups = [];
        $writtenColyGroups = [];
        $writtenBarangGroups = [];

        foreach ($this->lines as $line) {
            $barang = $line['barang'];
            $product = $line['product'];
            $kind = $line['kind'];
            $mergeGroup = $line['merge_group'];
            $isSub = $product?->isSubItem() ?? false;
            $isFirstInColyGroup = ! isset($writtenColyGroups[$mergeGroup]);
            $isFirstInBarangGroup = ! isset($writtenBarangGroups[$barang->id]);

            if ($isFirstInColyGroup) {
                $writtenColyGroups[$mergeGroup] = true;
            }

            if ($isFirstInBarangGroup) {
                $writtenBarangGroups[$barang->id] = true;
                $sheet->setCellValue("A{$row}", (string) ($barang->no_index ?? ''));
                $sheet->setCellValue("B{$row}", (string) ($barang->marking ?? ''));
                $sheet->setCellValue("F{$row}", $this->colyFinalForBarang($barang));
                $sheet->setCellValue('P'.$row, self::resolveBarangNotes($barang->notes));
                $sheet->setCellValue('Q'.$row, self::resolveKategoriLabel($barang->cbm_type));
                $sheet->setCellValue('R'.$row, $barang->tanggal_masuk?->format('n/j/Y') ?? '');
            }

            if ($kind === 'split_kg') {
                $pricing = \App\Support\BarangShippingPricing::calculate($barang);
                $sheet->setCellValue("D{$row}", '— KG (lebih CBM)');
                $sheet->setCellValue("M{$row}", \App\Support\CbmFormat::round($pricing['excess_cbm']));
                $sheet->setCellValue("O{$row}", round($pricing['excess_kg'], 2));
            } elseif ($product === null) {
                $sheet->setCellValue("D{$row}", $barang->itemsSummary());

                if ($isFirstInColyGroup) {
                    $summaryCtns = max(0, (int) $barang->ctns);
                    $sheet->setCellValue("E{$row}", $summaryCtns);
                    $sheet->setCellValue("M{$row}", \App\Support\CbmFormat::round((float) $barang->total_cbm));
                    $sheet->setCellValue(
                        "N{$row}",
                        $summaryCtns > 0
                            ? round((float) $barang->total_gross_weight / $summaryCtns, 2)
                            : round((float) $barang->total_gross_weight, 2),
                    );
                    $sheet->setCellValue("O{$row}", round((float) $barang->total_gross_weight, 2));
                }
            } elseif ($isSub) {
                $master = $this->resolveLogisticsParent($barang, $product);
                $ctns = max(1, (int) $master->ctns);
                $pcs = max(0, (int) $product->pcs_per_box);
                $totalPcs = $pcs > 0 ? $pcs * $ctns : 0;
                $name = trim((string) ($product->product_name ?? ''));

                if ($isFirstInColyGroup) {
                    $sheet->setCellValue("E{$row}", max(0, (int) $master->ctns));
                    $sheet->setCellValue("I{$row}", (float) $master->length_cm);
                    $sheet->setCellValue("J{$row}", (float) $master->width_cm);
                    $sheet->setCellValue("K{$row}", (float) $master->height_cm);
                    $sheet->setCellValue("L{$row}", $master->resolvedUnitCbm());
                    $sheet->setCellValue("M{$row}", $master->resolvedLineCbm());
                    $sheet->setCellValue("N{$row}", round((float) $master->gross_weight_kg, 2));
                    $sheet->setCellValue("O{$row}", round($master->lineGrossWeightKg(), 2));
                }

                if ($name !== '') {
                    $sheet->setCellValue("D{$row}", $name);
                }
                if ($pcs > 0) {
                    $sheet->setCellValue("G{$row}", $pcs);
                }
                if ($totalPcs > 0) {
                    $sheet->setCellValue("H{$row}", $totalPcs);
                }

                $this->embedProductPhoto($sheet, $product->photo_path, "C{$row}", $row);
            } else {
                $master = $product->logisticsSource();
                $ctns = max(0, (int) $master->ctns);
                $pcs = max(0, (int) $product->pcs_per_box);
                $totalPcs = $pcs > 0 ? $pcs * max(1, $ctns) : max(1, $ctns);
                $unitCbm = $master->resolvedUnitCbm();
                $lineCbm = $master->resolvedLineCbm();
                $name = (string) ($product->product_name ?? '');
                $hasSubItems = $this->parentHasSubItems($barang, $product);

                if ($isFirstInColyGroup) {
                    $sheet->setCellValue("E{$row}", $ctns);
                    $sheet->setCellValue("I{$row}", (float) $master->length_cm);
                    $sheet->setCellValue("J{$row}", (float) $master->width_cm);
                    $sheet->setCellValue("K{$row}", (float) $master->height_cm);
                    $sheet->setCellValue("L{$row}", $unitCbm);
                    $sheet->setCellValue("M{$row}", $lineCbm);
                    $sheet->setCellValue("N{$row}", round((float) $master->gross_weight_kg, 2));
                    $sheet->setCellValue("O{$row}", round($master->lineGrossWeightKg(), 2));
                }

                if (! $hasSubItems) {
                    $sheet->setCellValue("D{$row}", $name);
                    $sheet->setCellValue("G{$row}", $pcs);
                    $sheet->setCellValue("H{$row}", $totalPcs);
                    $this->embedProductPhoto($sheet, $product->photo_path, "C{$row}", $row);
                }
            }

            if ($kind === 'product') {
                $colyRowGroups[$mergeGroup][] = $row;
            }

            if ($kind !== 'split_kg') {
                $barangRowGroups[$barang->id][] = $row;
            }

            $row++;
        }

        $this->applyColumnMerges($sheet, $colyRowGroups, self::COLY_MERGE_COLUMNS);
        $this->applyColumnMerges($sheet, $barangRowGroups, self::BARANG_MERGE_COLUMNS);
    }

    private function colyFinalForBarang(Barang $barang): int
    {
        if ($barang->relationLoaded('products')) {
            return (int) $barang->products
                ->filter(static fn (BarangProduct $product): bool => $product->parent_id === null)
                ->sum(static fn (BarangProduct $product): int => max(0, (int) $product->ctns));
        }

        return max(0, (int) $barang->ctns);
    }

    private function parentHasSubItems(Barang $barang, BarangProduct $parent): bool
    {
        $parentKey = (int) $parent->getKey();
        if ($parentKey <= 0) {
            return false;
        }

        return $barang->products->contains(
            static fn (BarangProduct $row): bool => (int) $row->parent_id === $parentKey,
        );
    }

    private function resolveLogisticsParent(Barang $barang, BarangProduct $product): BarangProduct
    {
        if (! $product->isSubItem()) {
            return $product->logisticsSource();
        }

        if ($product->relationLoaded('parent') && $product->parent instanceof BarangProduct) {
            return $product->parent;
        }

        $parentId = (int) $product->parent_id;
        if ($barang->relationLoaded('products') && $parentId > 0) {
            $parent = $barang->products->first(
                static fn (BarangProduct $row): bool => (int) $row->getKey() === $parentId,
            );

            if ($parent instanceof BarangProduct) {
                return $parent;
            }
        }

        return $product->logisticsSource();
    }

    /**
     * @param  array<string|int, list<int>>  $rowGroups
     * @param  list<string>  $columns
     */
    private function applyColumnMerges(Worksheet $sheet, array $rowGroups, array $columns): void
    {
        foreach ($rowGroups as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $startRow = min($rows);
            $endRow = max($rows);

            foreach ($columns as $column) {
                $range = "{$column}{$startRow}:{$column}{$endRow}";
                $sheet->mergeCells($range);
                $sheet->getStyle($range)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }
    }

    private function lastDataRow(Worksheet $sheet): int
    {
        $lastRow = self::DATA_START_ROW - 1;

        for ($row = self::DATA_START_ROW; $row <= $sheet->getHighestRow(); $row++) {
            foreach (range('A', self::LAST_COLUMN) as $column) {
                $value = $sheet->getCell($column.$row)->getValue();
                if ($value !== null && $value !== '') {
                    $lastRow = $row;
                    break;
                }
            }
        }

        return max(self::DATA_START_ROW - 1, $lastRow);
    }

    private function applyKategoriColumnFill(Worksheet $sheet, int $lastRow): void
    {
        if ($lastRow < self::DATA_START_ROW) {
            return;
        }

        for ($row = self::DATA_START_ROW; $row <= $lastRow; $row++) {
            $label = trim((string) $sheet->getCell('Q'.$row)->getValue());
            $color = self::resolveKategoriFillColor($label);
            if ($color === null) {
                continue;
            }

            $sheet->getStyle('Q'.$row)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $color],
                ],
            ]);
        }
    }

    private function embedProductPhoto(Worksheet $sheet, ?string $photoPath, string $cell, int $row): void
    {
        $sheet->getRowDimension($row)->setRowHeight(self::PHOTO_ROW_HEIGHT_PT);

        if ($photoPath === null || $photoPath === '') {
            return;
        }

        $diskPath = Storage::disk('public')->path($photoPath);
        if (! is_file($diskPath)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Product photo');
        $drawing->setDescription('Product photo');
        $drawing->setPath($diskPath);
        $drawing->setCoordinates($cell);
        $drawing->setWorksheet($sheet);

        $rowHeightPt = $sheet->getRowDimension($row)->getRowHeight();
        if ($rowHeightPt <= 0) {
            $rowHeightPt = self::PHOTO_ROW_HEIGHT_PT;
        }

        $cellWidthPx = $this->columnWidthToPixels(self::PHOTO_COLUMN_WIDTH);
        $cellHeightPx = (int) round($rowHeightPt * 96 / 72);
        $maxWidthPx = max(1, $cellWidthPx - (self::PHOTO_CELL_PADDING_PX * 2));
        $maxHeightPx = max(1, $cellHeightPx - (self::PHOTO_CELL_PADDING_PX * 2));

        $imageWidthPx = (int) round($drawing->getWidth());
        $imageHeightPx = (int) round($drawing->getHeight());

        if ($imageWidthPx > 0 && $imageHeightPx > 0) {
            $scale = min($maxWidthPx / $imageWidthPx, $maxHeightPx / $imageHeightPx);
            $drawing->setHeight((int) round($imageHeightPx * $scale));
            $imageWidthPx = (int) round($drawing->getWidth());
            $imageHeightPx = (int) round($drawing->getHeight());
        }

        $drawing->setOffsetX(max(0, (int) round(($cellWidthPx - $imageWidthPx) / 2)));
        $drawing->setOffsetY(max(0, (int) round(($cellHeightPx - $imageHeightPx) / 2)));
    }

    private function columnWidthToPixels(float $width): int
    {
        return (int) round($width * 7 + 5);
    }

    private function applyStyles(Worksheet $sheet, int $lastRow): void
    {
        $headerRow1 = self::HEADER_ROW_1;
        $headerRow2 = self::HEADER_ROW_2;

        $columnWidths = [
            'A' => 11,
            'B' => 16,
            'C' => self::PHOTO_COLUMN_WIDTH,
            'D' => 24,
            'E' => 10,
            'F' => 10,
            'G' => 10,
            'H' => 11,
            'I' => 9,
            'J' => 9,
            'K' => 9,
            'L' => 9,
            'M' => 11,
            'N' => 9,
            'O' => 11,
            'P' => 26,
            'Q' => 16,
            'R' => 12,
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $headerRange = "A{$headerRow1}:".self::LAST_COLUMN."{$headerRow2}";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8FAFC'],
            ],
        ]);

        foreach (['E', 'M', 'O'] as $col) {
            $sheet->getStyle("{$col}{$headerRow1}:{$col}{$headerRow2}")
                ->getFont()->getColor()->setRGB(self::RED);
        }

        $sheet->getRowDimension($headerRow1)->setRowHeight(22);
        $sheet->getRowDimension($headerRow2)->setRowHeight(20);

        if ($lastRow >= self::DATA_START_ROW) {
            $dataRange = 'A'.self::DATA_START_ROW.':'.self::LAST_COLUMN.$lastRow;
            $sheet->getStyle($dataRange)->applyFromArray([
                'font' => ['size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]);

            $sheet->getStyle('D'.self::DATA_START_ROW.":D{$lastRow}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('P'.self::DATA_START_ROW.":P{$lastRow}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);

            foreach (['E', 'M', 'O'] as $col) {
                $sheet->getStyle("{$col}".self::DATA_START_ROW.":{$col}{$lastRow}")
                    ->getFont()->getColor()->setRGB(self::RED);
            }

            $sheet->getStyle('E'.self::DATA_START_ROW.":F{$lastRow}")
                ->getNumberFormat()->setFormatCode(\App\Support\ExcelDecimalFormat::COUNT);
            $sheet->getStyle('G'.self::DATA_START_ROW.":H{$lastRow}")
                ->getNumberFormat()->setFormatCode(\App\Support\ExcelDecimalFormat::COUNT);
            $sheet->getStyle('I'.self::DATA_START_ROW.":K{$lastRow}")
                ->getNumberFormat()->setFormatCode(\App\Support\ExcelDecimalFormat::DIMENSION);
            $sheet->getStyle('L'.self::DATA_START_ROW.":M{$lastRow}")
                ->getNumberFormat()->setFormatCode(\App\Support\ExcelDecimalFormat::DECIMAL_3);
            $sheet->getStyle('N'.self::DATA_START_ROW.":O{$lastRow}")
                ->getNumberFormat()->setFormatCode(\App\Support\ExcelDecimalFormat::WEIGHT);
        }

        $tableEnd = max($headerRow2, $lastRow);
        $sheet->getStyle("A{$headerRow1}:".self::LAST_COLUMN.$tableEnd)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
        ]);

        if ($lastRow >= self::DATA_START_ROW) {
            $dataBorders = $sheet->getStyle('A'.self::DATA_START_ROW.':'.self::LAST_COLUMN.$lastRow)->getBorders();
            $dataBorders->setDiagonalDirection(Borders::DIAGONAL_NONE);
            $dataBorders->getDiagonal()->setBorderStyle(Border::BORDER_NONE);
        }

        $sheet->freezePane('A'.self::DATA_START_ROW);
    }
}
