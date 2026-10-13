<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Exports\FinanceInvoiceListSpreadsheetBuilder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

final class FinanceInvoiceListExport implements WithEvents, WithTitle
{
    /** @param list<array<string, mixed>> $blocks */
    public function __construct(private array $blocks) {}

    public function title(): string
    {
        return 'Invoice Pembayaran';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                (new FinanceInvoiceListSpreadsheetBuilder())->build(
                    $event->sheet->getDelegate(),
                    $this->blocks,
                );
            },
        ];
    }
}
