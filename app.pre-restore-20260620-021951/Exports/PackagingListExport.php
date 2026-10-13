<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Container;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PackagingListExport implements FromCollection, WithHeadings
{
    public function __construct(private Container $container)
    {
        $this->container->load('barangs.dimensions');
    }

    public function headings(): array
    {
        return ['No', 'No Index', 'Marking', 'CTNS', 'PCS', 'Material', 'CBM', 'GW (kg)', 'Destination'];
    }

    public function collection()
    {
        return $this->container->barangs->map(function ($b, $idx) {
            return [
                $idx + 1,
                $b->no_index,
                $b->marking ?? '-',
                $b->ctns,
                $b->pcs,
                $b->material ?? '-',
                $b->total_cbm,
                $b->total_gross_weight,
                $b->destination ?? '-',
            ];
        });
    }
}
