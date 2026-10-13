<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Invoice;
use App\Support\CbmFormat;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InvoiceExport implements FromCollection, WithHeadings
{
    public function __construct(private Invoice $invoice)
    {
        $this->invoice->load('items.barang');
    }

    public function headings(): array
    {
        return ['No', 'No Index', 'Marking', 'CBM', 'Harga/CBM', 'Asuransi', 'Extra', 'Line Total'];
    }

    public function collection()
    {
        return $this->invoice->items->map(function ($item, $idx) {
            return [
                $idx + 1,
                $item->barang->no_index ?? '-',
                $item->barang->marking ?? '-',
                CbmFormat::truncate((float) $item->cbm),
                $item->price_per_cbm,
                $item->insurance,
                $item->extra,
                $item->line_total,
            ];
        });
    }
}
