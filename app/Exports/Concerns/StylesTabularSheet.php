<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use App\Support\ExcelDecimalFormat;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

trait StylesTabularSheet
{
    /**
     * @return array{last_column: string, columns: array<string, string>}
     */
    abstract protected function tabularSheetStyleConfig(): array;

    abstract protected function tabularSheetDataRowCount(): int;

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $this->applyTabularSheetStyles($event);
            },
        ];
    }

    private function applyTabularSheetStyles(AfterSheet $event): void
    {
        $config = $this->tabularSheetStyleConfig();
        $lastColumn = $config['last_column'];
        $dataRowCount = $this->tabularSheetDataRowCount();
        $lastRow = max(1, $dataRowCount + 1);
        $tableRange = "A1:{$lastColumn}{$lastRow}";

        $sheet = $event->sheet->getDelegate();

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
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

        $sheet->getRowDimension(1)->setRowHeight(22);

        if ($dataRowCount > 0) {
            for ($row = 2; $row <= $lastRow; $row++) {
                if ($row % 2 === 0) {
                    $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'F8FAFC'],
                        ],
                    ]);
                }
            }
        }

        foreach ($config['columns'] as $column => $type) {
            if ($dataRowCount === 0) {
                continue;
            }

            $range = "{$column}2:{$column}{$lastRow}";

            match ($type) {
                'integer' => $sheet->getStyle($range)->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'numberFormat' => [
                        'formatCode' => NumberFormat::FORMAT_NUMBER,
                    ],
                ]),
                'cbm' => $sheet->getStyle($range)->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_RIGHT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'numberFormat' => [
                        'formatCode' => ExcelDecimalFormat::DECIMAL_4,
                    ],
                ]),
                'currency' => $sheet->getStyle($range)->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_RIGHT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'numberFormat' => [
                        'formatCode' => ExcelDecimalFormat::INTEGER,
                    ],
                ]),
                default => $sheet->getStyle($range)->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]),
            };
        }

        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ]);

        $sheet->freezePane('A2');
    }
}
