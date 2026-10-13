<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Exports\OmsetSpreadsheetBuilder;
use App\Services\Exports\OmsetTarifSheetBuilder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

final class OmsetExport implements WithEvents
{
    /**
     * @param array{
     *     groups: list<array<string, mixed>>,
     *     partai_label: ?string,
     *     company_fixed_amount: float,
     *     company_name: string,
     *     category_reference: list<array<string, mixed>>,
     * } $payload
     */
    public function __construct(private array $payload) {}

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $spreadsheet = $event->sheet->getDelegate()->getParent();
                if ($spreadsheet === null) {
                    return;
                }

                (new OmsetSpreadsheetBuilder())->build(
                    $event->sheet->getDelegate(),
                    $this->payload,
                );
                $omsetSheet = $event->sheet->getDelegate();
                $omsetSheet->setTitle('Omset');
                foreach (['A', 'B', 'C'] as $column) {
                    $dim = $omsetSheet->getColumnDimension($column);
                    $dim->setVisible(false);
                    $dim->setCollapsed(true);
                    $dim->setWidth(0);
                }

                $tarifSheet = $spreadsheet->createSheet();
                (new OmsetTarifSheetBuilder())->build($tarifSheet, $this->payload);

                $spreadsheet->setActiveSheetIndex(0);
            },
        ];
    }
}
