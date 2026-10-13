<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Exports\SuratJalanRekapanSpreadsheetBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SuratJalanRekapanExport
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly string $partai,
        private readonly ?string $containerNo = null,
    ) {
    }

    public function containerLabel(): string
    {
        $label = 'Container '.trim($this->partai);
        $containerNo = trim((string) ($this->containerNo ?? ''));
        if ($containerNo !== '') {
            $label .= ' · '.$containerNo;
        }

        return $label;
    }

    public function filename(): string
    {
        $safePartai = preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->partai) ?: 'container';

        return 'Rekapan-Surat-Jalan-'.$safePartai.'.xlsx';
    }

    public function spreadsheet(): Spreadsheet
    {
        return (new SuratJalanRekapanSpreadsheetBuilder())->build(
            $this->rows,
            $this->containerLabel(),
        );
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
