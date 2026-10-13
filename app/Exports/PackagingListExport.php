<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Container;
use App\Services\Exports\PackingListSpreadsheetBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PackagingListExport
{
    public function __construct(private readonly Container $container)
    {
    }

    public function filename(): string
    {
        return 'PL INV ' . trim((string) ($this->container->partai ?? $this->container->code ?? 'container')) . '.xlsx';
    }

    public function spreadsheet(): Spreadsheet
    {
        return (new PackingListSpreadsheetBuilder())->build($this->container);
    }

    public function download(): StreamedResponse
    {
        $spreadsheet = $this->spreadsheet();
        $filename = $this->filename();

        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }
}
