<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Barang;
use App\Support\CbmFormat;
use App\Support\RmbFormat;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BarangProductsExport implements WithEvents
{
    private const HEADER_ROW = 5;
    private const DATA_START_ROW = 6;
    private const LAST_COLUMN = 'M';

    public function __construct(private readonly Barang $barang)
    {
        $this->barang->load(['products' => fn ($q) => $q->orderBy('sort_order')->with('parent')]);
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

        $sheet->setCellValue('A1', '唛头 Marking code');
        $sheet->setCellValue('B1', $this->barang->marking ?? '-');
        $sheet->setCellValue('A2', '订单号 Order number');
        $sheet->setCellValue('B2', $this->barang->no_po ?? $this->barang->no_index ?? '-');
        $sheet->setCellValue('A3', '日期 Date');
        $sheet->setCellValue('B3', $this->barang->tanggal_masuk?->format('Y-m-d') ?? now()->format('Y-m-d'));

        $headers = [
            'A' => "箱号\nCTNS",
            'B' => "产品名称\nName of product",
            'C' => "品图\nPhoto product",
            'D' => "产品材质\nProduct material",
            'E' => "每箱PCS\nEach box (PCS)",
            'F' => "毛重\nGross Weight (KG)",
            'G' => "净重\nNet Weight (KG)",
            'H' => "长\nLength (CM)",
            'I' => "宽\nWidth (CM)",
            'J' => "高\nHeight (CM)",
            'K' => "立方\nCBM",
            'L' => "单价\nPRICE/UNIT (RMB)",
            'M' => "金额\nTOTAL PRICE (RMB)",
        ];

        foreach ($headers as $col => $label) {
            $sheet->setCellValue("{$col}" . self::HEADER_ROW, $label);
        }

        $row = self::DATA_START_ROW;
        $totals = [
            'ctns' => 0,
            'gross' => 0.0,
            'net' => 0.0,
            'cbm' => 0.0,
            'amount' => 0.0,
        ];

        foreach ($this->barang->products as $product) {
            $sheet->setCellValue("A{$row}", (int) $product->ctns);
            $sheet->setCellValue("B{$row}", $product->product_name ?? '');
            $sheet->setCellValue("D{$row}", $product->material ?? '');
            $sheet->setCellValue("E{$row}", (int) $product->pcs_per_box);
            $sheet->setCellValue("F{$row}", (float) $product->gross_weight_kg);
            $sheet->setCellValue("G{$row}", (float) $product->net_weight_kg);
            $sheet->setCellValue("H{$row}", (float) $product->length_cm);
            $sheet->setCellValue("I{$row}", (float) $product->width_cm);
            $sheet->setCellValue("J{$row}", (float) $product->height_cm);
            $sheet->setCellValue("K{$row}", (float) $product->volume_cbm);
            $sheet->setCellValue("L{$row}", (float) $product->price_per_unit_rmb);
            $sheet->setCellValue("M{$row}", (float) $product->total_price_rmb);

            $this->embedProductPhoto($sheet, $product->photo_path, "C{$row}", $row);

            $totals['ctns'] += (int) $product->ctns;
            $totals['gross'] += $product->lineGrossWeightKg();
            $totals['net'] += (float) $product->net_weight_kg * max(1, (int) $product->ctns);
            $totals['cbm'] += (float) $product->volume_cbm;
            $totals['amount'] += (float) $product->total_price_rmb;

            $row++;
        }

        if ($this->barang->products->isNotEmpty()) {
            $sheet->setCellValue("A{$row}", 'TOTAL');
            $sheet->setCellValue("A" . ($row + 1), $totals['ctns']);
            $sheet->setCellValue("F{$row}", $totals['gross']);
            $sheet->setCellValue("G{$row}", $totals['net']);
            $sheet->setCellValue("K{$row}", $totals['cbm']);
            $sheet->setCellValue("M{$row}", $totals['amount']);
            $sheet->mergeCells("A{$row}:D{$row}");
            $row++;
        }

        $lastDataRow = max(self::DATA_START_ROW, $row - 1);
        $this->applyStyles($sheet, $lastDataRow);
    }

    private function embedProductPhoto(Worksheet $sheet, ?string $photoPath, string $cell, int $row): void
    {
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
        $drawing->setCoordinates($cell);
        $drawing->setHeight(72);
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);

        $sheet->getRowDimension($row)->setRowHeight(58);
    }

    private function applyStyles(Worksheet $sheet, int $lastRow): void
    {
        $headerRow = self::HEADER_ROW;
        $tableEnd = max($lastRow, $headerRow);

        foreach (range('A', self::LAST_COLUMN) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('C')->setWidth(14);

        $sheet->getStyle("A1:B3")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
        ]);

        $sheet->getStyle("A{$headerRow}:" . self::LAST_COLUMN . "{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0F172A'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(36);

        if ($lastRow > $headerRow) {
            $sheet->getStyle('A' . ($headerRow + 1) . ':' . self::LAST_COLUMN . $lastRow)->applyFromArray([
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]);

            $sheet->getStyle("K" . ($headerRow + 1) . ":K{$lastRow}")
                ->getNumberFormat()->setFormatCode('#.##0,0000');
            $sheet->getStyle("L" . ($headerRow + 1) . ":M{$lastRow}")
                ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        }

        $sheet->getStyle("A{$headerRow}:" . self::LAST_COLUMN . $tableEnd)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ]);

        $sheet->freezePane('A' . ($headerRow + 1));
    }
}
