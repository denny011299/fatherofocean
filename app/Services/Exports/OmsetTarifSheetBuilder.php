<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Support\ExcelDecimalFormat;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class OmsetTarifSheetBuilder
{
    private const NAVY = '0F172A';

    private const STRIPE = 'F8FAFC';

    private const BORDER_GREY = 'CBD5E1';

    private const NOTE_GREY = '64748B';

    /**
     * @param array{
     *     category_reference: list<array{
     *         kategori: string,
     *         modal_cbm: float,
     *         modal_kg: float,
     *         profit_per_shipment: float,
     *         profit_is_fallback: bool,
     *     }>,
     *     company_fixed_amount: float,
     * } $payload
     */
    public function build(Worksheet $sheet, array $payload): void
    {
        $sheet->setTitle('Tarif Kategori');

        $sheet->setCellValue('A1', 'Referensi Tarif per Kategori');
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::NAVY],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $fallback = max(0, (float) ($payload['company_fixed_amount'] ?? 0));
        $sheet->setCellValue(
            'A2',
            'Keuntungan perusahaan = per pengiriman (per no urut). Tarif di bawah dari pengaturan sistem saat export dibuat.'
        );
        $sheet->mergeCells('A2:E2');
        $sheet->setCellValue(
            'A3',
            'Default pengaturan umum (jika kategori belum diisi): Rp '.number_format($fallback, 0, ',', '.').' / pengiriman'
        );
        $sheet->mergeCells('A3:E3');
        $sheet->getStyle('A2:A3')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => self::NOTE_GREY]],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);
        $sheet->getRowDimension(3)->setRowHeight(20);

        $headerRow = 5;
        $headers = [
            'A' => 'Kategori / Tipe Barang',
            'B' => 'Modal CBM (Rp)',
            'C' => 'Modal kg (Rp)',
            'D' => 'Keuntungan / Pengiriman (Rp)',
            'E' => 'Keterangan',
        ];
        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }
        $sheet->getStyle("A{$headerRow}:E{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
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

        $rows = $payload['category_reference'] ?? [];
        $dataRow = $headerRow + 1;
        foreach ($rows as $index => $row) {
            $current = $dataRow + $index;
            $sheet->setCellValue("A{$current}", (string) ($row['kategori'] ?? ''));
            $sheet->setCellValue("B{$current}", max(0, (float) ($row['modal_cbm'] ?? 0)));
            $sheet->setCellValue("C{$current}", max(0, (float) ($row['modal_kg'] ?? 0)));
            $sheet->setCellValue("D{$current}", max(0, (float) ($row['profit_per_shipment'] ?? 0)));
            $sheet->setCellValue(
                "E{$current}",
                ! empty($row['profit_is_fallback']) ? 'Pakai default umum' : '',
            );

            if ($index % 2 === 0) {
                $sheet->getStyle("A{$current}:E{$current}")->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::STRIPE],
                    ],
                ]);
            }
        }

        $lastRow = $rows === [] ? $headerRow : $dataRow + count($rows) - 1;
        if ($rows === []) {
            $sheet->setCellValue('A6', 'Belum ada kategori harga modal di pengaturan sistem.');
            $sheet->mergeCells('A6:E6');
            $lastRow = 6;
        }

        $sheet->getStyle("A{$headerRow}:E{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
            'font' => ['size' => 11],
        ]);

        ExcelDecimalFormat::apply($sheet, "B{$dataRow}:D{$lastRow}", ExcelDecimalFormat::RUPIAH);
        $sheet->getStyle("A{$dataRow}:A{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getStyle("E{$dataRow}:E{$lastRow}")->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
            'font' => ['size' => 10, 'color' => ['rgb' => self::NOTE_GREY]],
        ]);

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getColumnDimension('E')->setWidth(18);
    }
}
