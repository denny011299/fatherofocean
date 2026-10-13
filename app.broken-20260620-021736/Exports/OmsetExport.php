<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class OmsetExport implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private Collection $rows,
        private array $totals,
        private string $periodLabel,
    ) {}

    public function title(): string
    {
        return 'Laporan Omset';
    }

    public function headings(): array
    {
        return [
            'No',
            'Periode',
            'Marking',
            'Jumlah Kiriman',
            'Total CBM',
            'Total GW (kg)',
            'Omset (Rp)',
        ];
    }

    public function collection(): Collection
    {
        $data = $this->rows->map(function ($row, $idx) {
            return [
                $idx + 1,
                $row['period'],
                $row['marking'],
                $row['shipment_count'],
                number_format((float) $row['total_cbm'], 4, '.', ','),
                number_format((float) $row['total_gw'], 2, '.', ','),
                number_format((float) $row['omset'], 0, ',', '.'),
            ];
        });

        $data->push([
            '',
            'TOTAL',
            '',
            $this->totals['shipment_count'],
            number_format((float) $this->totals['total_cbm'], 4, '.', ','),
            number_format((float) $this->totals['total_gw'], 2, '.', ','),
            number_format((float) $this->totals['omset'], 0, ',', '.'),
        ]);

        return $data;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rows->count() + 2;

        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0F172A']]],
            $lastRow => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F0FDF4']]],
        ];
    }
}
