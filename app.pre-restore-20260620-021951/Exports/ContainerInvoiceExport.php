<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Container;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ContainerInvoiceExport implements FromCollection, WithHeadings
{
    public function __construct(private Container $container)
    {
        $this->container->load('barangs.customer');
    }

    public function headings(): array
    {
        return ['No', 'No Index', 'Marking', 'Customer', 'CBM', 'Harga/CBM', 'Extra', 'Line Total'];
    }

    public function collection()
    {
        return $this->container->barangs->map(function ($b, $idx) {
            $lineTotal = ($b->total_cbm * $b->price_per_cbm) + ($b->extra_charge ?? 0);

            return [
                $idx + 1,
                $b->no_index ?? '-',
                $b->marking ?? '-',
                $b->customer?->name ?? '-',
                $b->total_cbm,
                $b->price_per_cbm,
                $b->extra_charge ?? 0,
                $lineTotal,
            ];
        });
    }
}
