<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Barang;
use App\Support\CbmFormat;
use App\Support\ExcelDecimalFormat;
use App\Support\RmbFormat;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BarangProductsExport implements WithEvents
{
    private const TITLE_ROW = 1;

    private const META_ROW = 2;

    private const HEADER_ROW_1 = 3;

    private const HEADER_ROW_2 = 4;

    private const DATA_START_ROW = 5;

    private const LAST_COLUMN = 'L';

    private const NAVY = '0A2240';

    private const BORDER_GREY = 'CBD5E1';

    private const FORMAT_DECIMAL = '#.##0,00';

    private const FORMAT_INTEGER = '#.##0';

    private const PHOTO_COLUMN_WIDTH = 22;

    private const PHOTO_ROW_HEIGHT_PT = 72;

    private const PHOTO_IMAGE_HEIGHT_PX = 88;

    /** @var list<string> Merged vertically when one CTN has sub-products. */
    private const COLY_MERGE_COLUMNS = ['A', 'F', 'G', 'H', 'I', 'J'];

    public function __construct(private readonly Barang $barang)
    {
        $this->barang->load(['products' => fn ($q) => $q->orderBy('sort_order')->with('parent')]);
    }

    public static function filename(Barang $barang): string
    {
        $marking = $barang->marking ?? $barang->no_index ?? 'products';
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($marking)) ?: 'products';
        $date = $barang->tanggal_masuk?->format('Y-m-d') ?? now()->format('Y-m-d');

        return "{$slug}_{$date}.xlsx";
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
        $sheet->setTitle('Products');

        $metaDate = $this->barang->tanggal_masuk?->format('Y-m-d') ?? now()->format('Y-m-d');
        $marking = $this->barang->marking ?? '-';
        $orderNo = $this->barang->no_po ?? $this->barang->no_index ?? '-';

        $sheet->setCellValue('A' . self::TITLE_ROW, '装箱明细表 (Packing list)');
        $sheet->mergeCells('A' . self::TITLE_ROW . ':' . self::LAST_COLUMN . self::TITLE_ROW);

        $sheet->setCellValue('A' . self::META_ROW, "(Marking Code) 客户名称: {$marking}");
        $sheet->mergeCells('A' . self::META_ROW . ':E' . self::META_ROW);

        $sheet->setCellValue('F' . self::META_ROW, "(Order Number) 订单号: {$orderNo}");
        $sheet->mergeCells('F' . self::META_ROW . ':I' . self::META_ROW);

        $sheet->setCellValue('I' . self::META_ROW, "(Date) 日期: {$metaDate}");
        $sheet->mergeCells('I' . self::META_ROW . ':' . self::LAST_COLUMN . self::META_ROW);

        $headerRow1 = self::HEADER_ROW_1;
        $headerRow2 = self::HEADER_ROW_2;

        $singleHeaders = [
            'A' => "箱号\nCTNS",
            'B' => "产品名称\nName of product",
            'C' => "品图\nPhoto product",
            'D' => "产品材质\nProduct material",
            'E' => "每箱PCS\nEach box (PCS)",
            'F' => "毛重\nGross Weight (KG)",
            'K' => "单价\nPRICE/UNIT (RMB)",
            'L' => "金额\nTOTAL PRICE (RMB)",
        ];

        foreach ($singleHeaders as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow1}", $label);
            $sheet->mergeCells("{$col}{$headerRow1}:{$col}{$headerRow2}");
        }

        $sheet->setCellValue("G{$headerRow1}", "外袋尺寸 (CM)\nOuter bag size");
        $sheet->mergeCells("G{$headerRow1}:J{$headerRow1}");

        $dimensionSubHeaders = [
            'G' => "长度\nLength",
            'H' => "宽度\nWidth",
            'I' => "高度\nHeight",
            'J' => "立方\nCBM",
        ];

        foreach ($dimensionSubHeaders as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow2}", $label);
        }

        $row = self::DATA_START_ROW;
        /** @var array<int, list<int>> $mergeRowsByParent */
        $mergeRowsByParent = [];
        $totalCtns = 0;
        $totalGw = 0.0;
        $totalCbm = 0.0;
        $totalPrice = 0.0;

        foreach ($this->buildProductGroups() as $group) {
            $master = $group['master'];
            $groupStartRow = $row;
            $groupAmount = 0.0;

            foreach ($group['items'] as $itemIndex => $product) {
                $lineAmount = (float) $product->total_price_rmb;
                if ($lineAmount <= 0) {
                    $pcs = max(0, (int) $product->pcs_per_box);
                    $unit = (float) $product->price_per_unit_rmb;
                    $ctns = max(1, (int) $master->ctns);
                    $qty = $product->isSubItem()
                        ? ($pcs > 0 ? $pcs : $ctns)
                        : ($pcs > 0 ? $pcs * $ctns : $ctns);
                    if ($unit > 0 && $qty > 0) {
                        $lineAmount = RmbFormat::lineTotal($unit, $qty);
                    }
                }
                $groupAmount += $lineAmount;

                $sheet->setCellValue("B{$row}", $product->product_name ?? '');
                $sheet->setCellValue("D{$row}", trim((string) ($product->material ?? $master->material ?? '')));
                $sheet->setCellValue("E{$row}", (int) $product->pcs_per_box);
                $sheet->setCellValue("K{$row}", (float) $product->price_per_unit_rmb);
                $sheet->setCellValue("L{$row}", RmbFormat::round($lineAmount));

                if ($itemIndex === 0) {
                    $sheet->setCellValue("A{$row}", (int) $master->ctns);
                    $sheet->setCellValue("F{$row}", round($master->lineGrossWeightKg(), 2));
                    $sheet->setCellValue("G{$row}", (float) $master->length_cm);
                    $sheet->setCellValue("H{$row}", (float) $master->width_cm);
                    $sheet->setCellValue("I{$row}", (float) $master->height_cm);
                    $sheet->setCellValue("J{$row}", CbmFormat::truncate($master->resolvedLineCbm()));
                }

                $this->embedProductPhoto($sheet, $product->photo_path, "C{$row}", $row);

                $row++;
            }

            $groupEndRow = $row - 1;

            if ($groupEndRow > $groupStartRow) {
                $mergeRowsByParent[(int) $group['key']] = range($groupStartRow, $groupEndRow);
            }

            $totalCtns += (int) $master->ctns;
            $totalGw += $master->lineGrossWeightKg();
            $totalCbm += $master->resolvedLineCbm();
            $totalPrice += $groupAmount;
        }

        $this->applyColyVerticalMerges($sheet, $mergeRowsByParent);

        $lastDataRow = max(self::DATA_START_ROW - 1, $row - 1);
        $totalRow = $row;

        if ($this->barang->products->isNotEmpty()) {
            $sheet->setCellValue("A{$totalRow}", 'TOTAL');
            $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
            $sheet->setCellValue("E{$totalRow}", $totalCtns);
            $sheet->setCellValue("F{$totalRow}", round($totalGw, 2));
            $sheet->setCellValue("J{$totalRow}", CbmFormat::truncate($totalCbm));
            $sheet->setCellValue("L{$totalRow}", RmbFormat::round($totalPrice));
            $lastDataRow = $totalRow;
        }

        $this->applyStyles($sheet, $lastDataRow, $totalRow);
    }

    /**
     * @return list<array{key: int, master: \App\Models\BarangProduct, items: list<\App\Models\BarangProduct>}>
     */
    private function buildProductGroups(): array
    {
        /** @var list<array{key: int, master: \App\Models\BarangProduct, items: list<\App\Models\BarangProduct>}> $groups */
        $groups = [];
        /** @var array<int, int> $indexByParent */
        $indexByParent = [];

        foreach ($this->barang->products as $product) {
            $parentKey = $product->isSubItem()
                ? (int) $product->parent_id
                : (int) $product->id;
            $master = $product->logisticsSource();

            if (! isset($indexByParent[$parentKey])) {
                $indexByParent[$parentKey] = count($groups);
                $groups[] = [
                    'key' => $parentKey,
                    'master' => $master,
                    'items' => [],
                ];
            }

            $groups[$indexByParent[$parentKey]]['items'][] = $product;
        }

        return $groups;
    }

    /**
     * @param  array<int, list<int>>  $mergeRowsByParent
     */
    private function applyColyVerticalMerges(Worksheet $sheet, array $mergeRowsByParent): void
    {
        foreach ($mergeRowsByParent as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $startRow = min($rows);
            $endRow = max($rows);

            foreach (self::COLY_MERGE_COLUMNS as $column) {
                $range = "{$column}{$startRow}:{$column}{$endRow}";
                $sheet->mergeCells($range);
                $sheet->getStyle($range)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setWrapText(true);
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
        if (!is_file($diskPath)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Product photo');
        $drawing->setDescription('Product photo');
        $drawing->setPath($diskPath);
        $drawing->setHeight(self::PHOTO_IMAGE_HEIGHT_PX);
        $drawing->setCoordinates($cell);
        $drawing->setWorksheet($sheet);

        $cellWidthPx = $this->columnWidthToPixels(self::PHOTO_COLUMN_WIDTH);
        $cellHeightPx = (int) round(self::PHOTO_ROW_HEIGHT_PT * 96 / 72);
        $imageWidthPx = (int) round($drawing->getWidth());
        $imageHeightPx = (int) round($drawing->getHeight());

        $offsetX = max(0, (int) round(($cellWidthPx - $imageWidthPx) / 2));
        $offsetY = max(0, (int) round(($cellHeightPx - $imageHeightPx) / 2));

        $drawing->setOffsetX($offsetX);
        $drawing->setOffsetY($offsetY);
    }

    private function columnWidthToPixels(float $width): int
    {
        return (int) round($width * 7 + 5);
    }

    private function applyStyles(Worksheet $sheet, int $tableEndRow, int $totalRow): void
    {
        $headerRow1 = self::HEADER_ROW_1;
        $headerRow2 = self::HEADER_ROW_2;

        $columnWidths = [
            'A' => 9,
            'B' => 22,
            'C' => self::PHOTO_COLUMN_WIDTH,
            'D' => 16,
            'E' => 11,
            'F' => 12,
            'G' => 11,
            'H' => 10,
            'I' => 10,
            'J' => 11,
            'K' => 14,
            'L' => 14,
        ];

        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->getStyle('A' . self::TITLE_ROW . ':' . self::LAST_COLUMN . self::TITLE_ROW)->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => self::NAVY]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(self::TITLE_ROW)->setRowHeight(28);

        $sheet->getStyle('A' . self::META_ROW . ':E' . self::META_ROW)->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '334155']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle('F' . self::META_ROW . ':I' . self::META_ROW)->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '334155']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle('I' . self::META_ROW . ':' . self::LAST_COLUMN . self::META_ROW)->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '334155']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension(self::META_ROW)->setRowHeight(22);

        $headerRange = "A{$headerRow1}:" . self::LAST_COLUMN . "{$headerRow2}";
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
        $sheet->getRowDimension($headerRow1)->setRowHeight(36);
        $sheet->getRowDimension($headerRow2)->setRowHeight(32);

        if ($tableEndRow > $headerRow2) {
            $dataEnd = $this->barang->products->isNotEmpty() ? $totalRow - 1 : $tableEndRow;
            $dataStart = self::DATA_START_ROW;

            $sheet->getStyle("A{$dataStart}:" . self::LAST_COLUMN . $dataEnd)->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'font' => ['size' => 10],
            ]);

            $sheet->getStyle("A{$dataStart}:A{$dataEnd}")
                ->getNumberFormat()->setFormatCode(self::FORMAT_INTEGER);
            $sheet->getStyle("E{$dataStart}:E{$dataEnd}")
                ->getNumberFormat()->setFormatCode(self::FORMAT_INTEGER);
            $sheet->getStyle("F{$dataStart}:F{$dataEnd}")
                ->getNumberFormat()->setFormatCode(self::FORMAT_DECIMAL);
            $sheet->getStyle("G{$dataStart}:I{$dataEnd}")
                ->getNumberFormat()->setFormatCode(self::FORMAT_DECIMAL);
            $sheet->getStyle("J{$dataStart}:J{$dataEnd}")
                ->getNumberFormat()->setFormatCode(ExcelDecimalFormat::DECIMAL_3);
            $sheet->getStyle("K{$dataStart}:L{$dataEnd}")
                ->getNumberFormat()->setFormatCode(self::FORMAT_DECIMAL);
        }

        if ($this->barang->products->isNotEmpty()) {
            $sheet->getStyle("A{$totalRow}:" . self::LAST_COLUMN . "{$totalRow}")->applyFromArray([
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

            $sheet->getStyle("F{$totalRow}")
                ->getNumberFormat()->setFormatCode(self::FORMAT_DECIMAL);
            $sheet->getStyle("J{$totalRow}")
                ->getNumberFormat()->setFormatCode(ExcelDecimalFormat::DECIMAL_3);
            $sheet->getStyle("L{$totalRow}")
                ->getNumberFormat()->setFormatCode(self::FORMAT_DECIMAL);
        }

        $sheet->getStyle("A{$headerRow1}:" . self::LAST_COLUMN . $tableEndRow)->applyFromArray([
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
