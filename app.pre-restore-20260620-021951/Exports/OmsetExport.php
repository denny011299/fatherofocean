<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Exports\OmsetSpreadsheetBuilder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

final class OmsetExport implements WithEvents
{
    /**
     * @param array{
     *     groups: list<array{
     *         marketing_name: string,
     *         rows: list<array<string, mixed>>,
     *     }>,
     *     partai_label: ?string,
     *     company_fixed_amount: float,
     *     company_name: string,
     * } $payload
     */
    public function __construct(private array $payload) {}

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                (new OmsetSpreadsheetBuilder())->build(
                    $event->sheet->getDelegate(),
                    $this->payload,
                );
            },
        ];
    }
}
