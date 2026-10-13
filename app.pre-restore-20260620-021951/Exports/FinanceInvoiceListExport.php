<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class FinanceInvoiceListExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows) {}

    public function title(): string
    {
        return 'Invoice Pembayaran';
    }

    public function headings(): array
    {
        return [
            'Invoice No',
            'Marking',
            'Marketing',
            'Partai',
            'Pengiriman',
            'Grand Total (Rp)',
            'Terbayar (Rp)',
            'Sisa (Rp)',
            'Jatuh Tempo',
            'Tanggal Lunas',
            'Status',
        ];
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(function (array $row): array {
            $status = $row['status'] ?? [];

            return [
                $row['invoice_no'] ?? '-',
                $row['marking_label'] ?? '-',
                $row['marketing_label'] ?? '-',
                $row['partai_label'] ?? '-',
                $row['consolidated_summary'] ?? (string) ($row['shipment_count'] ?? 1),
                number_format((float) ($row['grand_total'] ?? 0), 0, ',', '.'),
                number_format((float) ($row['total_paid'] ?? 0), 0, ',', '.'),
                number_format((float) ($row['remaining'] ?? 0), 0, ',', '.'),
                $row['due_date'] ?? '-',
                $row['tanggal_lunas'] ?? '-',
                is_array($status) ? ($status['label'] ?? '-') : (string) $status,
            ];
        });
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
