<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class MarkingExport implements WithEvents, WithTitle
{
    private const HEADER_ROW_1 = 1;

    private const HEADER_ROW_2 = 2;

    private const DATA_START_ROW = 3;

    private const HEADER_FILL = 'BDD7EE';

    /** @param  Collection<int, array<int, mixed>>  $rows */
    public function __construct(
        private readonly Collection $rows,
        private readonly bool $showPrices,
    ) {}

    public function title(): string
    {
        return 'Marking';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $this->writeHeaders($sheet);
                $this->writeData($sheet);
                $this->applyStyles($sheet);
            },
        ];
    }

    private function writeHeaders(Worksheet $sheet): void
    {
        $r1 = self::HEADER_ROW_1;
        $r2 = self::HEADER_ROW_2;

        $singles = [
            'A' => 'Branch',
            'B' => 'Marketing',
            'C' => 'Marking',
            'D' => 'Customer Name',
            'E' => 'Address',
            'H' => 'Status',
            'I' => 'Invoice Due (days)',
            'J' => 'Min. Charge (CBM)',
        ];

        if ($this->showPrices) {
            $singles['K'] = 'Max Weight per 1 CBM (KG)';
            $singles['L'] = 'Effective Start';
            $singles['P'] = 'NIK';
            $singles['Q'] = 'ID Card Name';
            $singles['R'] = 'Kelengkapan Data Lain';
        } else {
            $singles['K'] = 'NIK';
            $singles['L'] = 'ID Card Name';
            $singles['M'] = 'Kelengkapan Data Lain';
        }

        foreach ($singles as $col => $label) {
            $sheet->setCellValue("{$col}{$r1}", $label);
            $sheet->mergeCells("{$col}{$r1}:{$col}{$r2}");
        }

        $sheet->setCellValue("F{$r1}", 'Phone');
        $sheet->mergeCells("F{$r1}:G{$r1}");
        $sheet->setCellValue("F{$r2}", 'Contact 1');
        $sheet->setCellValue("G{$r2}", 'Contact 2');

        if ($this->showPrices) {
            $sheet->setCellValue("M{$r1}", 'Pricing');
            $sheet->mergeCells("M{$r1}:O{$r1}");
            $sheet->setCellValue("M{$r2}", 'Kategori');
            $sheet->setCellValue("N{$r2}", 'Harga');
            $sheet->setCellValue("O{$r2}", 'Item');
        }
    }

    private function writeData(Worksheet $sheet): void
    {
        $row = self::DATA_START_ROW;

        foreach ($this->rows as $values) {
            $colIndex = 1;
            foreach ($values as $value) {
                $col = Coordinate::stringFromColumnIndex($colIndex);
                $coord = "{$col}{$row}";
                if (is_string($value)) {
                    $sheet->setCellValueExplicit($coord, $value, DataType::TYPE_STRING);
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValue($coord, $value);
                } else {
                    $sheet->setCellValueExplicit($coord, (string) ($value ?? ''), DataType::TYPE_STRING);
                }
                $colIndex++;
            }
            $row++;
        }
    }

    private function applyStyles(Worksheet $sheet): void
    {
        $lastCol = $this->showPrices ? 'R' : 'M';
        $lastDataRow = self::DATA_START_ROW + max(0, $this->rows->count()) - 1;
        $headerRange = "A".self::HEADER_ROW_1.":{$lastCol}".self::HEADER_ROW_2;
        $tableEnd = max($lastDataRow, self::HEADER_ROW_2);
        $tableRange = "A".self::HEADER_ROW_1.":{$lastCol}{$tableEnd}";

        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '000000'],
                'size' => 11,
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
        ]);

        $sheet->getRowDimension(self::HEADER_ROW_1)->setRowHeight(20);
        $sheet->getRowDimension(self::HEADER_ROW_2)->setRowHeight(20);

        if ($lastDataRow >= self::DATA_START_ROW) {
            $dataRange = 'A'.self::DATA_START_ROW.":{$lastCol}{$lastDataRow}";
            $sheet->getStyle($dataRange)->applyFromArray([
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]);

            $itemCol = $this->showPrices ? 'O' : null;
            if ($itemCol !== null) {
                $sheet->getStyle("{$itemCol}".self::DATA_START_ROW.":{$itemCol}{$lastDataRow}")
                    ->getAlignment()
                    ->setWrapText(true);
            }
        }

        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        foreach (range(1, Coordinate::columnIndexFromString($lastCol)) as $i) {
            $col = Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->freezePane('A'.self::DATA_START_ROW);
    }
}
