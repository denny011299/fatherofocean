<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Container;
use App\Services\Exports\PackingListSpreadsheetBuilder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BulkPackagingListExport
{
    /** @param Collection<int, Container>|list<Container> $containers */
    public function __construct(private readonly Collection $containers)
    {
    }

    public static function filename(): string
    {
        return 'PL INV Multi Container ' . now()->format('Y-m-d') . '.xlsx';
    }

    public function spreadsheet(): Spreadsheet
    {
        return (new PackingListSpreadsheetBuilder())->buildMany($this->containers);
    }

    public function download(): StreamedResponse
    {
        $spreadsheet = $this->spreadsheet();
        $filename = self::filename();

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
