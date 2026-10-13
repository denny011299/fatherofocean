<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Support\ExcelDecimalFormat;
use App\Support\TerimaBarangPdfData;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BarangListProductsExport implements WithEvents
{
    private const HEADER_ROW_1 = 1;

    private const HEADER_ROW_2 = 2;

    private const DATA_START_ROW = 3;

    private const LAST_COLUMN = 'P';

    private const RED = 'FF0000';

    private const BORDER_GREY = '000000';

    /** Pastel green for coly groups with sub-items (parent + sub rows). */
    private const PASTEL_GREEN_SUB_ITEM = 'E8F5E9';

    private const PHOTO_COLUMN_WIDTH = 14;

    private const PHOTO_ROW_HEIGHT_PT = 52;

    private const PHOTO_CELL_PADDING_PX = 2;

    /** @var list<string> Columns merged vertically when No urut + marking match on consecutive rows. */
    private const DISPLAY_IDENTITY_MERGE_COLUMNS = [
        'A',
        'B',
    ];

    /** @var list<string> Columns merged vertically when one coly has sub-products. */
    private const SHIPMENT_MERGE_COLUMNS = [
        'E',
        'H',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
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

    /**
     * @param  EloquentCollection<int, Barang>  $barangs
     */
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
            : (int) $product->id;

        return $barang->id.':coly:'.$parentId;
    }

    private function displayIdentityKey(Barang $barang): string
    {
        return (string) ($barang->no_index ?? '') . '|' . (string) ($barang->marking ?? '');
    }

    public static function filename(): string
    {
        return 'daftar-kirim-produk-' . now()->format('Y-m-d_His') . '.xlsx';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $this->buildSheet($event->sheet->getDelegate());
            },
        ];
    }

    private function buildSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Daftar Kirim');

        $headerRow1 = self::HEADER_ROW_1;
        $headerRow2 = self::HEADER_ROW_2;

        $singleHeaders = [
            'A' => 'No urut',
            'B' => 'marking',
            'C' => 'Foto',
            'D' => 'Item',
            'E' => 'Total coly',
            'F' => 'PCS/BOX',
            'G' => 'Total PCS',
            'K' => 'CBM',
            'L' => 'Toatal CBM',
            'M' => 'Berat',
            'N' => 'Total Berat',
            'O' => 'Notes',
            'P' => 'Tanggal',
        ];

        foreach ($singleHeaders as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow1}", $label);
            $sheet->mergeCells("{$col}{$headerRow1}:{$col}{$headerRow2}");
        }

        $sheet->setCellValue("H{$headerRow1}", 'Dimensi');
        $sheet->mergeCells("H{$headerRow1}:J{$headerRow1}");

        $dimensionSubHeaders = [
            'H' => 'P',
            'I' => 'L',
            'J' => 'T',
        ];

        foreach ($dimensionSubHeaders as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow2}", $label);
        }

        $row = self::DATA_START_ROW;
        /** @var array<string, list<int>> $mergeRowGroups */
        $mergeRowGroups = [];
        $writtenMergeGroups = [];
        /** @var list<array{row: int, display_key: string}> */
        $displayIdentityRows = [];
        $previousDisplayKey = null;

        foreach ($this->lines as $line) {
            $barang = $line['barang'];
            $product = $line['product'];
            $kind = $line['kind'];
            $mergeGroup = $line['merge_group'];
            $isFirstInMergeGroup = ! isset($writtenMergeGroups[$mergeGroup]);
            $writtenMergeGroups[$mergeGroup] = true;
            $mergeRowGroups[$mergeGroup][] = $row;

            $displayKey = $this->displayIdentityKey($barang);
            $displayIdentityRows[] = ['row' => $row, 'display_key' => $displayKey];
            $isFirstInDisplayRun = $displayKey !== $previousDisplayKey;
            $previousDisplayKey = $displayKey;

            if ($isFirstInDisplayRun) {
                $sheet->setCellValue("A{$row}", (string) ($barang->no_index ?? ''));
                $sheet->setCellValue("B{$row}", (string) ($barang->marking ?? ''));
            }

            if ($isFirstInMergeGroup) {
                $sheet->setCellValue("P{$row}", $barang->tanggal_masuk?->format('n/j/Y') ?? '');
            }

            if ($kind === 'split_kg') {
                $pricing = \App\Support\BarangShippingPricing::calculate($barang);
                $sheet->setCellValue("D{$row}", '— KG (lebih CBM)');
                $sheet->setCellValue("O{$row}", TerimaBarangPdfData::formatPricingNotes($barang, $pricing['excess_cbm']));
                $sheet->setCellValue("L{$row}", \App\Support\CbmFormat::round($pricing['excess_cbm']));
                $sheet->setCellValue("N{$row}", round($pricing['excess_kg'], 2));
            } elseif ($product === null) {
                $sheet->setCellValue("D{$row}", $barang->itemsSummary());

                if ($isFirstInMergeGroup) {
                    $summaryCtns = max(0, (int) $barang->ctns);
                    $sheet->setCellValue("E{$row}", $summaryCtns);
                    $sheet->setCellValue("L{$row}", \App\Support\CbmFormat::round((float) $barang->total_cbm));
                    $sheet->setCellValue(
                        "M{$row}",
                        $summaryCtns > 0
                            ? round((float) $barang->total_gross_weight / $summaryCtns, 2)
                            : round((float) $barang->total_gross_weight, 2),
                    );
                    $sheet->setCellValue("N{$row}", round((float) $barang->total_gross_weight, 2));
                    $sheet->setCellValue("O{$row}", TerimaBarangPdfData::formatPricingNotes($barang, (float) $barang->total_cbm));
                }
            } else {
                $master = $product->logisticsSource();
                $ctns = max(0, (int) $master->ctns);
                $pcs = max(0, (int) $product->pcs_per_box);
                $totalPcs = $product->isSubItem()
                    ? ($pcs > 0 ? $pcs : max(1, $ctns))
                    : ($pcs > 0 ? $pcs * max(1, $ctns) : max(1, $ctns));
                $unitCbm = $ctns > 0
                    ? \App\Support\CbmFormat::round((float) $master->volume_cbm / $ctns)
                    : \App\Support\CbmFormat::round((float) $master->volume_cbm);
                $name = (string) ($product->product_name ?? '');

                $sheet->setCellValue("D{$row}", $name);
                $sheet->setCellValue("F{$row}", $pcs);
                $sheet->setCellValue("G{$row}", $totalPcs);

                if ($isFirstInMergeGroup) {
                    $sheet->setCellValue("E{$row}", $ctns);
                    $sheet->setCellValue("H{$row}", (float) $master->length_cm);
                    $sheet->setCellValue("I{$row}", (float) $master->width_cm);
                    $sheet->setCellValue("J{$row}", (float) $master->height_cm);
                    $sheet->setCellValue("K{$row}", $unitCbm);
                    $sheet->setCellValue("L{$row}", \App\Support\CbmFormat::round((float) $master->volume_cbm));
                    $sheet->setCellValue("M{$row}", round((float) $master->gross_weight_kg, 2));
                    $sheet->setCellValue(
                        "N{$row}",
                        round($master->lineGrossWeightKg(), 2),
                    );
                    $sheet->setCellValue(
                        "O{$row}",
                        TerimaBarangPdfData::formatPricingNotes($barang, (float) $barang->total_cbm),
                    );
                }

                $this->embedProductPhoto($sheet, $product->photo_path, "C{$row}", $row);
            }

            $row++;
        }

        $lastRow = max(self::DATA_START_ROW, $row - 1);
        $this->applyDisplayIdentityColumnMerges($sheet, $displayIdentityRows);
        $this->applyShipmentColumnMerges($sheet, $mergeRowGroups);
        $this->applyStyles($sheet, $lastRow);
        $this->applySubItemGroupRowFill($sheet, $mergeRowGroups);
    }

    /**
     * Merge No urut (A) and marking (B) for each consecutive run of identical values.
     *
     * @param  list<array{row: int, display_key: string}>  $displayIdentityRows
     */
    private function applyDisplayIdentityColumnMerges(Worksheet $sheet, array $displayIdentityRows): void
    {
        $runRows = [];
        $runKey = null;

        $flushRun = function () use ($sheet, &$runRows): void {
            if (count($runRows) < 2) {
                $runRows = [];

                return;
            }

            $startRow = min($runRows);
            $endRow = max($runRows);

            foreach (self::DISPLAY_IDENTITY_MERGE_COLUMNS as $column) {
                $range = "{$column}{$startRow}:{$column}{$endRow}";
                $sheet->mergeCells($range);
                $sheet->getStyle($range)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $runRows = [];
        };

        foreach ($displayIdentityRows as $entry) {
            if ($runKey !== null && $entry['display_key'] !== $runKey) {
                $flushRun();
            }

            $runKey = $entry['display_key'];
            $runRows[] = $entry['row'];
        }

        $flushRun();
    }

    /**
     * @param  array<string, list<int>>  $mergeRowGroups
     */
    private function applyShipmentColumnMerges(Worksheet $sheet, array $mergeRowGroups): void
    {
        foreach ($mergeRowGroups as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $startRow = min($rows);
            $endRow = max($rows);

            foreach (self::SHIPMENT_MERGE_COLUMNS as $column) {
                $range = "{$column}{$startRow}:{$column}{$endRow}";
                $sheet->mergeCells($range);
                $sheet->getStyle($range)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
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
        $drawing->setHeight(self::PHOTO_IMAGE_HEIGHT_PX);
        $drawing->setCoordinates($cell);
        $drawing->setWorksheet($sheet);

        $rowHeightPt = $sheet->getRowDimension($row)->getRowHeight();
        if ($rowHeightPt <= 0) {
            $rowHeightPt = self::PHOTO_ROW_HEIGHT_PT;
        }

        $cellWidthPx = $this->columnWidthToPixels(self::PHOTO_COLUMN_WIDTH);
        $cellHeightPx = (int) round($rowHeightPt * 96 / 72);
        $paddingPx = 2;
        $maxWidthPx = max(1, $cellWidthPx - ($paddingPx * 2));
        $maxHeightPx = max(1, $cellHeightPx - ($paddingPx * 2));

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

    /**
     * Highlight entire data rows for coly groups that include sub-items (same rule as shipment merges).
     *
     * @param  array<string, list<int>>  $mergeRowGroups
     */
    private function applySubItemGroupRowFill(Worksheet $sheet, array $mergeRowGroups): void
    {
        foreach ($mergeRowGroups as $mergeGroup => $rows) {
            if (count($rows) < 2 || ! str_contains((string) $mergeGroup, ':coly:')) {
                continue;
            }

            foreach ($rows as $row) {
                $sheet->getStyle('A' . $row . ':' . self::LAST_COLUMN . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::PASTEL_GREEN_SUB_ITEM],
                    ],
                ]);
            }
        }
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
            'G' => 11,
            'H' => 9,
            'I' => 9,
            'J' => 9,
            'K' => 9,
            'L' => 11,
            'M' => 9,
            'N' => 11,
            'O' => 26,
            'P' => 12,
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $headerRange = "A{$headerRow1}:" . self::LAST_COLUMN . "{$headerRow2}";
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

        foreach (['E', 'L', 'N'] as $col) {
            $sheet->getStyle("{$col}{$headerRow1}:{$col}{$headerRow2}")
                ->getFont()->getColor()->setRGB(self::RED);
        }

        $sheet->getRowDimension($headerRow1)->setRowHeight(22);
        $sheet->getRowDimension($headerRow2)->setRowHeight(20);

        if ($lastRow >= self::DATA_START_ROW) {
            $dataRange = 'A' . self::DATA_START_ROW . ':' . self::LAST_COLUMN . $lastRow;
            $sheet->getStyle($dataRange)->applyFromArray([
                'font' => ['size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]);

            $sheet->getStyle('D' . self::DATA_START_ROW . ":D{$lastRow}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);

            foreach (['E', 'L', 'N'] as $col) {
                $sheet->getStyle("{$col}" . self::DATA_START_ROW . ":{$col}{$lastRow}")
                    ->getFont()->getColor()->setRGB(self::RED);
            }

            $sheet->getStyle('E' . self::DATA_START_ROW . ":G{$lastRow}")
                ->getNumberFormat()->setFormatCode(ExcelDecimalFormat::INTEGER);
            $sheet->getStyle('H' . self::DATA_START_ROW . ":N{$lastRow}")
                ->getNumberFormat()->setFormatCode(ExcelDecimalFormat::DECIMAL_2);
            $sheet->getStyle('K' . self::DATA_START_ROW . ":L{$lastRow}")
                ->getNumberFormat()->setFormatCode(ExcelDecimalFormat::DECIMAL_3);
        }

        $tableEnd = max($headerRow2, $lastRow);
        $sheet->getStyle("A{$headerRow1}:" . self::LAST_COLUMN . $tableEnd)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
        ]);

        $sheet->freezePane('A' . self::DATA_START_ROW);
    }
}
