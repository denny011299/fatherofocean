<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Exports\InvoiceBlankSpreadsheetBuilder;
use App\Support\InvoicePdfData;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoiceBlankTemplateExport
{
    public static function filename(): string
    {
        return 'invoice-blank-template.xlsx';
    }

    public function spreadsheet(): Spreadsheet
    {
        return (new InvoiceBlankSpreadsheetBuilder())->build(InvoicePdfData::blankTemplate());
    }

    public function download(): StreamedResponse
    {
        $spreadsheet = $this->spreadsheet();

        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
            },
            self::filename(),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }
}
