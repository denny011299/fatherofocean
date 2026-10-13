<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\CbmFormat;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class SalesRevenueExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        private array $rows,
        private bool $includeSalesColumn,
    ) {}

    public function title(): string
    {
        return 'Penghasilan Sales';
    }

    public function headings(): array
    {
        $headings = [
            'No. Urut',
            'Marking',
        ];

        if ($this->includeSalesColumn) {
            $headings[] = 'Sales / Supervisor';
        }

        return array_merge($headings, [
            'Tgl Masuk',
            'Total CBM',
            'Total Omset (Rp)',
            'Rate (%)',
            'Estimasi (Rp)',
            'Fix (Rp)',
            'Status Komisi',
        ]);
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(function (array $row, int $idx): array {
            $line = [
                $row['no_index'] ?? '-',
                $row['marking'] ?? '-',
            ];

            if ($this->includeSalesColumn) {
                $line[] = $row['sales_name'] ?? '-';
            }

            return array_merge($line, [
                $row['tanggal_masuk'] ?? '-',
                CbmFormat::format((float) ($row['total_cbm'] ?? 0), '.', ''),
                number_format((float) ($row['sales_commission_base'] ?? 0), 0, ',', '.'),
                number_format((float) ($row['commission_rate'] ?? 0), 2, '.', ''),
                number_format((float) ($row['estimated_amount'] ?? 0), 0, ',', '.'),
                $row['confirmed_amount'] !== null
                    ? number_format((float) $row['confirmed_amount'], 0, ',', '.')
                    : '-',
                $this->statusLabel((string) ($row['status'] ?? '')),
            ]);
        });
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'waiting' => 'Menunggu',
            'confirmed' => 'Dikonfirmasi',
            'cair' => 'Cair',
            default => $status,
        };
    }
}
