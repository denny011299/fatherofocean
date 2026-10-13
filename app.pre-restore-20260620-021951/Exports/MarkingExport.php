<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class MarkingExport implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    /** @param  Collection<int, array<int, mixed>>  $rows */
    public function __construct(
        private readonly Collection $rows,
        private readonly bool $showPrices,
    ) {}

    public function title(): string
    {
        return 'Marking';
    }

    public function headings(): array
    {
        $headings = [
            __('marking.export_col_branch'),
            __('marking.export_col_marketing'),
            __('marking.export_col_code'),
            __('marking.export_col_customer'),
            __('marking.export_col_address'),
            __('marking.export_col_phone'),
            __('marking.export_col_status'),
            __('marking.export_col_invoice_due'),
            __('marking.export_col_min_charge'),
        ];

        if ($this->showPrices) {
            $headings[] = __('marking.export_col_max_weight');
            $headings[] = __('marking.export_col_effective_start');
            $headings[] = __('marking.export_col_pricing');
        }

        $headings[] = __('marking.export_col_nik');
        $headings[] = __('marking.export_col_id_card_name');

        return $headings;
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '0F172A']],
            ],
        ];
    }
}
