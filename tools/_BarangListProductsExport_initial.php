<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Barang;
use App\Models\BarangProduct;
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

    private const PHOTO_COLUMN_WIDTH = 14;

    private const PHOTO_ROW_HEIGHT_PT = 52;

    private const PHOTO_IMAGE_HEIGHT_PX = 64;

    /**
     * @var list<array{
     *     barang: Barang,
     *     product: ?BarangProduct,
     *     kind: string,
     * }>
     */
    private array $lines = [];

    /**
     * @param  EloquentCollection<int, Barang>  $barangs
     */
    public function __construct(EloquentCollection $barangs)
    {
        foreach ($barangs as $barang) {
            $rateSuffix = TerimaBarangPdfData::formatPriceRateSuffix($barang);
            $notesTotal = TerimaBarangPdfData::formatPricingNotes($barang, (float) $barang->total_cbm);

            if ($barang->products->isEmpty()) {
                $this->lines[] = [
                    'barang' => $barang,
                    'product' => null,
                    'kind' => 'product',
                ];
                $this->appendPricingLines($barang, $rateSuffix, $notesTotal);

                continue;
            }

            foreach ($barang->products as $product) {
                $this->lines[] = [
                    'barang' => $barang,
                    'product' => $product,
                    'kind' => 'product',
                ];
            }

            $this->appendPricingLines($barang, $rateSuffix, $notesTotal);
        }
    }

    /**
     * @param  EloquentCollection<int, Barang>  $barangs
     */
    private function appendPricingLines(Barang $barang, string $rateSuffix, string $notesTotal): void
    {
        $pricing = \App\Support\BarangShippingPricing::calculate($barang);

        if ($pricing['method'] !== \App\Support\BarangShippingPricing::METHOD_SPLIT) {
            return;
        }

        $this->lines[] = [
            'barang' => $barang,
            'product' => null,
            'kind' => 'split_kg',
        ];
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

        foreach ($this->lines as $line) {
            $barang = $line['barang'];
            $product = $line['product'];
            $kind = $line['kind'];
            $rateSuffix = TerimaBarangPdfData::formatPriceRateSuffix($barang);

            $sheet->setCellValue("A{$row}", (string) ($barang->no_index ?? ''));
            $sheet->setCellValue("B{$row}", (string) ($barang->marking ?? ''));
            $sheet->setCellValue("P{$row}", $barang->tanggal_masuk?->format('n/j/Y') ?? '');

            if ($kind === 'split_kg') {
                $pricing = \App\Support\BarangShippingPricing::calculate($barang);
                $sheet->setCellValue(
                    "D{$row}",
                    TerimaBarangPdfData::appendItemsWithPriceRates('— KG (lebih CBM)', $rateSuffix),
                );
                $sheet->setCellValue("O{$row}", TerimaBarangPdfData::formatPricingNotes($barang, $pricing['excess_cbm']));
                $sheet->setCellValue("L{$row}", round($pricing['excess_cbm'], 4));
                $sheet->setCellValue("N{$row}", round($pricing['excess_kg'], 2));
            } elseif ($product === null) {
                $sheet->setCellValue(
                    "D{$row}",
                    TerimaBarangPdfData::appendItemsWithPriceRates($barang->itemsSummary(), $rateSuffix),
                );
                $sheet->setCellValue("E{$row}", (int) $barang->ctns);
                $sheet->setCellValue("L{$row}", round((float) $barang->total_cbm, 4));
                $sheet->setCellValue("N{$row}", round((float) $barang->total_gross_weight, 2));
                $sheet->setCellValue("O{$row}", TerimaBarangPdfData::formatPricingNotes($barang, (float) $barang->total_cbm));
            } else {
                $ctns = max(0, (int) $product->ctns);
                $pcs = max(0, (int) $product->pcs_per_box);
                $totalPcs = $pcs > 0 ? $pcs * max(1, $ctns) : max(1, $ctns);
                $unitCbm = $ctns > 0
                    ? round((float) $product->volume_cbm / $ctns, 4)
                    : round((float) $product->volume_cbm, 4);
                $name = (string) ($product->product_name ?? '');

                $sheet->setCellValue(
                    "D{$row}",
                    TerimaBarangPdfData::appendItemsWithPriceRates($name, $rateSuffix),
                );
                $sheet->setCellValue("E{$row}", $ctns);
                $sheet->setCellValue("F{$row}", $pcs);
                $sheet->setCellValue("G{$row}", $totalPcs);
                $sheet->setCellValue("H{$row}", (float) $product->length_cm);
                $sheet->setCellValue("I{$row}", (float) $product->width_cm);
                $sheet->setCellValue("J{$row}", (float) $product->height_cm);
                $sheet->setCellValue("K{$row}", $unitCbm);
                $sheet->setCellValue("L{$row}", round((float) $product->volume_cbm, 4));
                $sheet->setCellValue("M{$row}", (float) $product->gross_weight_kg);
                $sheet->setCellValue("N{$row}", round($product->lineGrossWeightKg(), 2));
                $sheet->setCellValue(
                    "O{$row}",
                    TerimaBarangPdfData::formatPricingNotes($barang, (float) $barang->total_cbm),
                );

                $this->embedProductPhoto($sheet, $product->photo_path, "C{$row}", $row);
            }

            $row++;
        }

        $lastRow = max(self::DATA_START_ROW, $row - 1);
        $this->applyStyles($sheet, $lastRow);
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

        $cellWidthPx = $this->columnWidthToPixels(self::PHOTO_COLUMN_WIDTH);
        $cellHeightPx = (int) round(self::PHOTO_ROW_HEIGHT_PT * 96 / 72);
        $imageWidthPx = (int) round($drawing->getWidth());
        $imageHeightPx = (int) round($drawing->getHeight());

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
            'G' => 11,
            'H' => 9,
            'I' => 9,
            'J' => 9,
            'K' => 9,
            'L' => 11,
            'M' => 9,
            'N' => 11,
            'O' => 18,
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
                ->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('H' . self::DATA_START_ROW . ":N{$lastRow}")
                ->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('K' . self::DATA_START_ROW . ":L{$lastRow}")
                ->getNumberFormat()->setFormatCode('#,##0.0000');
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
