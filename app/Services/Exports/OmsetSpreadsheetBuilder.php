<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Support\CbmFormat;
use App\Support\ExcelDecimalFormat;
use App\Support\InvoicePdfData;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class OmsetSpreadsheetBuilder
{
    private const HEADER_ROW = 1;

    private const FIRST_DATA_ROW = 2;

    private const LAST_COLUMN = 'AJ';

    private const LAST_MAIN_HEADER_COLUMN = 'AC';

    private const FIRST_COMPANY_COLUMN = 'AD';

    private const NAVY = '0F172A';

    private const BLACK = '000000';

    private const COMPANY_PINK = 'E6B9B8';

    private const BORDER_GREY = 'CBD5E1';

    /** Header biru tabel ringkasan komisi (kanan sheet). */
    private const SUMMARY_BLUE = '9BC2E6';

    /** @var list<string> */
    private const HEADERS = [
        'No',
        'No Index',
        'Tanggal Masuk',
        'Marking',
        'Item',
        'Kategori',
        'CBM',
        'Total CBM',
        'Max CBM per marking',
        'KG',
        'Total KG',
        'Harga Modal CBM',
        'Harga Modal Kg',
        'Keterangan',
        'Harga jual CBM',
        'Harga Jual Kg',
        'Aditional Fee',
        'Keterangan',
        'Total Modal',
        'Total Jual',
        'Grand Total bayar',
        'Selisih (Laba Kotor)',
            'Catatan',
            'PIC',
            'Sales',
            'Pembayaran',
        'Update',
        'Loading',
        'Partai',
        'Profit Perusahaan',
        'Modal Perusahaan',
        'Total Modal Perusahaan',
        'Komisi Perusahaan',
        'Komisi Sales',
        'Komisi Sales',
        'Komisi PIC',
    ];

    /** @var array<string, float> */
    private const COLUMN_WIDTHS = [
        'A' => 5.6640625,
        'B' => 15.109375,
        'C' => 15.109375,
        'D' => 20.109375,
        'E' => 17.5546875,
        'F' => 17.5546875,
        'G' => 12.88671875,
        'H' => 12.88671875,
        'I' => 14.6640625,
        'J' => 13.109375,
        'K' => 12.88671875,
        'L' => 20.21875,
        'M' => 16.5546875,
        'N' => 16.5546875,
        'O' => 16.109375,
        'P' => 16.109375,
        'Q' => 16.109375,
        'R' => 16.109375,
        'S' => 21.33203125,
        'T' => 24.109375,
        'U' => 33.77734375,
        'V' => 24.109375,
        'W' => 28.0,
        'X' => 18.21875,
        'Y' => 11.33203125,
        'Z' => 16.44140625,
        'AA' => 15.88671875,
        'AB' => 8.6640625,
        'AC' => 7.33203125,
        'AD' => 18.6640625,
        'AE' => 17.109375,
        'AF' => 18.5546875,
        'AG' => 17.21875,
        'AH' => 18.21875,
        'AI' => 18.6640625,
        'AJ' => 17.109375,
        'AM' => 18.0,
        'AN' => 18.0,
        'AO' => 3.0,
        'AP' => 18.0,
        'AQ' => 18.0,
    ];

    /**
     * @param array{
     *     groups: list<array{
     *         marketing_name: string,
     *         sales_id: ?int,
     *         commission_rate: float,
     *         rows: list<array<string, mixed>>,
     *         ocean_link_rows?: list<array<string, mixed>>,
     *     }>,
     *     partai_label: ?string,
     *     company_fixed_amount: float,
     *     company_name: string,
     * } $payload
     */
    public function build(Worksheet $sheet, array $payload): void
    {
        $sheet->setTitle('Omset');
        $this->writeMainHeaders($sheet);

        $currentRow = self::FIRST_DATA_ROW;
        $firstTableRow = $currentRow;
        $lastDataRow = self::HEADER_ROW;
        /** @var list<int> */
        $dataRows = [];
        $defaultCompanyProfit = max(0, (float) ($payload['company_fixed_amount'] ?? 0));

        $activeGroups = array_values(array_filter(
            $payload['groups'],
            static fn (array $group): bool => $group['rows'] !== []
                || ($group['ocean_link_rows'] ?? []) !== [],
        ));

        /** @var list<array{name: string, start: int, end: int}> */
        $commissionGroups = [];

        foreach ($activeGroups as $group) {
            $sectionStartRow = $currentRow;
            $markNo = 1;
            $previousMarking = null;
            /** @var list<array{start: int, end: int, marking: string, minimum_charge_cbm: float}> */
            $markingRanges = [];
            $openMarkingStart = null;
            $openMarkingKey = null;
            $openMarkingMinCharge = 0.0;

            $rows = $group['rows'];
            $oceanLinkRows = $group['ocean_link_rows'] ?? [];
            if ($oceanLinkRows !== []) {
                foreach ($oceanLinkRows as $oceanRow) {
                    $rows[] = $oceanRow;
                }
            }

            $groupCommissionRate = max(0.0, (float) ($group['commission_rate'] ?? 0));
            $groupSalesName = trim((string) ($group['marketing_name'] ?? ''));

            foreach ($rows as $rowData) {
                $marking = (string) ($rowData['marking'] ?? '');
                $rowMinCharge = max(0.0, (float) ($rowData['minimum_charge_cbm'] ?? 0));
                $showNo = $previousMarking === null || $marking !== $previousMarking;
                if ($showNo) {
                    $rowData['_row_no'] = $markNo;
                    $markNo++;
                    $previousMarking = $marking;
                }

                if ($openMarkingKey === null) {
                    $openMarkingStart = $currentRow;
                    $openMarkingKey = $marking;
                    $openMarkingMinCharge = $rowMinCharge;
                } elseif ($marking !== $openMarkingKey) {
                    $markingRanges[] = [
                        'start' => (int) $openMarkingStart,
                        'end' => $currentRow - 1,
                        'marking' => (string) $openMarkingKey,
                        'minimum_charge_cbm' => $openMarkingMinCharge,
                    ];
                    $openMarkingStart = $currentRow;
                    $openMarkingKey = $marking;
                    $openMarkingMinCharge = $rowMinCharge;
                } else {
                    $openMarkingMinCharge = max($openMarkingMinCharge, $rowMinCharge);
                }

                if (trim((string) ($rowData['sales_name'] ?? '')) === '' && $groupSalesName !== '') {
                    $rowData['sales_name'] = $groupSalesName;
                }

                $commissionRate = max(
                    0.0,
                    (float) ($rowData['commission_rate'] ?? $groupCommissionRate),
                );
                $companyProfit = (float) ($rowData['company_loyalty_amount'] ?? 0);
                if ($companyProfit <= 0) {
                    $companyProfit = $defaultCompanyProfit;
                }

                $isDetailRow = $showNo || $this->rowHasDetailFields($rowData);
                $this->writeDataRow(
                    $sheet,
                    $currentRow,
                    $rowData,
                    $isDetailRow,
                    $commissionRate,
                    $companyProfit,
                );
                $dataRows[] = $currentRow;
                $lastDataRow = $currentRow;
                $currentRow++;
            }

            if ($openMarkingKey !== null && $openMarkingStart !== null) {
                $markingRanges[] = [
                    'start' => $openMarkingStart,
                    'end' => $currentRow - 1,
                    'marking' => $openMarkingKey,
                    'minimum_charge_cbm' => $openMarkingMinCharge,
                ];
            }

            $sectionEndRow = $currentRow - 1;
            if ($sectionEndRow >= $sectionStartRow) {
                $this->applyMarkingGroupFormulas($sheet, $markingRanges);

                if ($groupSalesName !== '') {
                    $commissionGroups[] = [
                        'name' => $groupSalesName,
                        'start' => $sectionStartRow,
                        'end' => $sectionEndRow,
                    ];
                }
            }
        }

        if ($lastDataRow >= self::FIRST_DATA_ROW) {
            $totalsRow = $currentRow;
            $this->writeGrandCommissionTotalsRow($sheet, $totalsRow, $lastDataRow);
            $this->applyTableStyles($sheet, $firstTableRow, $lastDataRow, $dataRows);
            $this->applyCompanyColumnStyles($sheet, $firstTableRow, $lastDataRow);
            $this->applyTotalsRowStyles($sheet, $totalsRow);
            $this->writeCommissionSummaryTables($sheet, $firstTableRow, $lastDataRow, $commissionGroups);
        } else {
            $this->applyColumnWidths($sheet);
        }

        $this->hideColumnsAandC($sheet);
    }

    private function writeMainHeaders(Worksheet $sheet): void
    {
        foreach (self::HEADERS as $index => $heading) {
            $column = self::columnLetter($index);
            $sheet->setCellValue("{$column}".self::HEADER_ROW, $heading);
        }

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(22.05);
        $this->applyHeaderStyles($sheet);
    }

    /**
     * @param array<string, mixed> $rowData
     */
    private function rowHasDetailFields(array $rowData): bool
    {
        return ($rowData['tanggal_masuk'] ?? '') !== ''
            || ($rowData['item'] ?? '') !== ''
            || ($rowData['kategori'] ?? '') !== '';
    }

    /**
     * @param array<string, mixed> $rowData
     */
    private function writeDataRow(
        Worksheet $sheet,
        int $row,
        array $rowData,
        bool $isDetailRow,
        float $commissionRate,
        float $companyProfit,
    ): void {
        if (isset($rowData['_row_no'])) {
            $sheet->setCellValue("A{$row}", (int) $rowData['_row_no']);
        }

        $sheet->setCellValue("B{$row}", (string) ($rowData['serial_no'] ?? ''));

        if (($rowData['tanggal_masuk'] ?? '') !== '') {
            $sheet->setCellValue("C{$row}", (string) $rowData['tanggal_masuk']);
        }

        $sheet->setCellValue("D{$row}", (string) ($rowData['marking'] ?? ''));

        if (($rowData['item'] ?? '') !== '') {
            $sheet->setCellValue("E{$row}", (string) $rowData['item']);
        }

        if (($rowData['kategori'] ?? '') !== '') {
            $sheet->setCellValue("F{$row}", (string) $rowData['kategori']);
        }

        // Volume aktual per baris; Total CBM (H) + overweight memakai agregat marking (total-first).
        $actualCbm = CbmFormat::truncate((float) ($rowData['cbm_actual'] ?? $rowData['cbm'] ?? 0));
        if ($actualCbm > 0) {
            ExcelDecimalFormat::setOptionalDecimal($sheet, "G{$row}", $actualCbm, CbmFormat::DECIMALS);
        }

        $thresholdKg = $this->weightThresholdFromRow($rowData);
        if ($thresholdKg > 0) {
            ExcelDecimalFormat::setOptionalDecimal($sheet, "I{$row}", $thresholdKg, 0);
        }

        if (($rowData['kg'] ?? null) !== null && (float) $rowData['kg'] > 0) {
            ExcelDecimalFormat::setOptionalDecimal($sheet, "J{$row}", (float) $rowData['kg'], 2);
        }

        if ((float) ($rowData['modal_cbm_price'] ?? 0) > 0) {
            $sheet->setCellValue("L{$row}", (float) $rowData['modal_cbm_price']);
        }

        if ((float) ($rowData['modal_kg_price'] ?? 0) > 0) {
            $sheet->setCellValue("M{$row}", (float) $rowData['modal_kg_price']);
        }

        $this->setFormula($sheet, "N{$row}", self::keteranganFormula($row, $row));

        if ((float) ($rowData['sell_cbm_price'] ?? 0) > 0) {
            $sheet->setCellValue("O{$row}", (float) $rowData['sell_cbm_price']);
        }

        if ((float) ($rowData['sell_kg_price'] ?? 0) > 0) {
            $sheet->setCellValue("P{$row}", (float) $rowData['sell_kg_price']);
        }

        if ((float) ($rowData['additional_fee'] ?? 0) > 0) {
            $sheet->setCellValue("Q{$row}", (float) $rowData['additional_fee']);
        }

        $feeNote = trim((string) ($rowData['additional_fee_note'] ?? ''));
        if ($feeNote !== '') {
            $sheet->setCellValue("R{$row}", $feeNote);
        }

        // Provisional; applyMarkingGroupFormulas menulis ulang pakai Total CBM/KG marking.
        $this->setFormula($sheet, "S{$row}", self::totalModalFormula($row, $row, $row, true));
        $this->setFormula($sheet, "T{$row}", self::totalJualFormula($row, $row, $row, true));
        $this->setFormula($sheet, "V{$row}", self::selisihLabaKotorFormula($row));

        $catatan = trim((string) ($rowData['catatan'] ?? $rowData['notes'] ?? ''));
        if ($catatan !== '') {
            $this->writeCatatanCell($sheet, $row, $catatan);
        } else {
            $sheet->getRowDimension($row)->setRowHeight($isDetailRow ? -1 : 19.5);
        }

        $marketingStaff = trim((string) ($rowData['marketing_staff'] ?? ''));
        if ($marketingStaff !== '') {
            $sheet->setCellValue("X{$row}", $marketingStaff);
        }

        $salesName = trim((string) ($rowData['sales_name'] ?? $rowData['marketing_name'] ?? ''));
        if ($salesName !== '') {
            $sheet->setCellValue("Y{$row}", $salesName);
        }

        $sheet->setCellValue("Z{$row}", (string) ($rowData['payment_status'] ?? 'belum lunas'));
        $sheet->setCellValue("AA{$row}", (string) ($rowData['status_update'] ?? ''));

        $loadingExcel = $rowData['tanggal_masuk_excel'] ?? null;
        if ($loadingExcel !== null) {
            $sheet->setCellValue("AB{$row}", (float) $loadingExcel);
        } elseif (($rowData['tanggal_masuk'] ?? '') !== '') {
            $sheet->setCellValue("AB{$row}", '-');
        }

        $partai = trim((string) ($rowData['partai'] ?? ''));
        if ($partai !== '') {
            $sheet->setCellValue("AC{$row}", $partai);
        }

        if ($companyProfit > 0) {
            $sheet->setCellValue("AD{$row}", $companyProfit);
        }

        $this->setFormula($sheet, "AE{$row}", "=L{$row}+AD{$row}");
        // Provisional; diganti di applyMarkingGroupFormulas untuk total-first marking.
        $this->setFormula($sheet, "AF{$row}", self::totalModalPerusahaanFormula($row, $row, $row, true));
        $this->setFormula($sheet, "AG{$row}", "=AD{$row}*G{$row}");

        // Numeric fraction + % format (0.025 → 2.50%). Avoid locale text "2,50%"
        // which Excel/WPS treats as text and breaks AI/AJ formulas (#VALUE!).
        $sheet->setCellValue("AH{$row}", $commissionRate / 100.0);
        ExcelDecimalFormat::apply($sheet, "AH{$row}", ExcelDecimalFormat::PERCENT);

        $this->setFormula($sheet, "AI{$row}", "=(T{$row}-Q{$row})*AH{$row}");
        $this->setFormula($sheet, "AJ{$row}", "=T{$row}-AF{$row}-AI{$row}");
    }

    /** Catatan multi-baris: wrap ke bawah + tinggi baris agak longgar. */
    private function writeCatatanCell(Worksheet $sheet, int $row, string $catatan): void
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $catatan));
        $sheet->setCellValue("W{$row}", $normalized);
        $sheet->getStyle("W{$row}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $displayLines = 0;
        // ~28 karakter per baris untuk lebar kolom V ~28.
        $charsPerLine = 28;
        foreach (explode("\n", $normalized) as $line) {
            $len = max(1, mb_strlen($line));
            $displayLines += (int) max(1, (int) ceil($len / $charsPerLine));
        }

        // ~18pt per baris + padding — lebih longgar dari default Excel.
        $height = max(28.0, ($displayLines * 18.0) + 10.0);
        $sheet->getRowDimension($row)->setRowHeight(min(120.0, $height));
    }

    /**
     * @param list<array{start: int, end: int, marking: string, minimum_charge_cbm?: float}> $markingRanges
     */
    private function applyMarkingGroupFormulas(Worksheet $sheet, array $markingRanges): void
    {
        foreach ($markingRanges as $range) {
            $start = $range['start'];
            $end = $range['end'];
            $minCharge = CbmFormat::truncate(max(0.0, (float) ($range['minimum_charge_cbm'] ?? 0)));

            if ($end > $start) {
                $sheet->mergeCells("D{$start}:D{$end}");
                $sheet->mergeCells("H{$start}:H{$end}");
                $sheet->mergeCells("K{$start}:K{$end}");
                $sheet->mergeCells("N{$start}:N{$end}");
                $sheet->mergeCells("U{$start}:U{$end}");

                // Total CBM = max(sum aktual, min charge marking) — sama pola invoice total-first.
                $this->setFormula(
                    $sheet,
                    "H{$start}",
                    self::totalCbmFormula($start, $end, $minCharge),
                );
                $this->setFormula($sheet, "K{$start}", "=SUM(J{$start}:J{$end})");
                $this->setFormula($sheet, "U{$start}", "=SUM(T{$start}:T{$end})");

                $this->styleMergedCenter($sheet, "D{$start}:D{$end}", false);
                $this->styleMergedCenter($sheet, "H{$start}:H{$end}", false);
                $this->styleMergedCenter($sheet, "K{$start}:K{$end}", false);
                $this->styleMergedCenter($sheet, "N{$start}:N{$end}", false);
                $this->styleMergedCenter($sheet, "U{$start}:U{$end}", false);
            } else {
                // Single-row marking: Total CBM = billable total-first (aktual vs min charge).
                $g = $sheet->getCell("G{$start}")->getValue();
                $j = $sheet->getCell("J{$start}")->getValue();
                if (is_numeric($g)) {
                    $actual = CbmFormat::truncate((float) $g);
                    $billable = InvoicePdfData::billableVolumeForTotals($actual, $minCharge);
                    ExcelDecimalFormat::setOptionalDecimal(
                        $sheet,
                        "H{$start}",
                        $billable,
                        CbmFormat::DECIMALS,
                    );
                }
                if (is_numeric($j)) {
                    ExcelDecimalFormat::setOptionalDecimal($sheet, "K{$start}", (float) $j, 2);
                }
                // Grand Total bayar: sama fungsinya dengan SUM 1 baris.
                $this->setFormula($sheet, "U{$start}", "=T{$start}");
            }

            // Keterangan / Total Modal / Total Jual / Modal Perusahaan: overweight dari Total CBM×threshold vs Total KG.
            for ($row = $start; $row <= $end; $row++) {
                $isLead = $row === $start;
                $this->setFormula($sheet, "N{$row}", self::keteranganFormula($start, $row));
                $this->setFormula($sheet, "S{$row}", self::totalModalFormula($row, $start, $end, $isLead));
                $this->setFormula($sheet, "T{$row}", self::totalJualFormula($row, $start, $end, $isLead));
                $this->setFormula($sheet, "AF{$row}", self::totalModalPerusahaanFormula($row, $start, $end, $isLead));
            }
        }
    }

    private function writeGrandCommissionTotalsRow(Worksheet $sheet, int $row, int $lastDataRow): void
    {
        $this->setFormula($sheet, "AG{$row}", "=SUM(AG1:AG{$lastDataRow})");
        $this->setFormula($sheet, "AI{$row}", "=SUM(AI1:AI{$lastDataRow})");
        $this->setFormula($sheet, "AJ{$row}", "=SUM(AJ1:AJ{$lastDataRow})");
        $sheet->getRowDimension($row)->setRowHeight(18.0);
    }

    /**
     * Ringkasan kanan sheet: KOMISI PIC (AM:AN) + KOMISI SALES (AP:AQ).
     * Total dijumlah per nama lewat SUMIF ke kolom acuan (X=PIC → AJ, Y=Sales → AI).
     *
     * @param list<array{name: string, start: int, end: int}> $salesGroups
     */
    private function writeCommissionSummaryTables(
        Worksheet $sheet,
        int $firstDataRow,
        int $lastDataRow,
        array $salesGroups,
    ): void {
        $picNames = $this->uniqueNamesFromColumn($sheet, 'X', $firstDataRow, $lastDataRow);
        $salesNames = $this->uniqueNamesFromColumn($sheet, 'Y', $firstDataRow, $lastDataRow);

        // Fallback: kalau kolom Sales kosong, pakai nama grup sales.
        if ($salesNames === []) {
            foreach ($salesGroups as $group) {
                $name = trim((string) ($group['name'] ?? ''));
                if ($name !== '' && ! in_array($name, $salesNames, true)) {
                    $salesNames[] = $name;
                }
            }
        }

        if ($picNames === [] && $salesNames === []) {
            return;
        }

        $headerRow = self::FIRST_DATA_ROW;

        $sheet->setCellValue("AM{$headerRow}", 'KOMISI PIC');
        $sheet->setCellValue("AN{$headerRow}", 'TOTAL');
        $sheet->setCellValue("AP{$headerRow}", 'KOMISI SALES');
        $sheet->setCellValue("AQ{$headerRow}", 'TOTAL');
        $this->styleSummaryHeader($sheet, "AM{$headerRow}:AN{$headerRow}");
        $this->styleSummaryHeader($sheet, "AP{$headerRow}:AQ{$headerRow}");

        $dataStart = $headerRow + 1;
        $picEnd = $dataStart - 1;
        $row = $dataStart;
        foreach ($picNames as $name) {
            $sheet->setCellValue("AM{$row}", mb_strtoupper($name));
            $this->setFormula(
                $sheet,
                "AN{$row}",
                self::sumIfByNameFormula('X', $name, 'AJ', $firstDataRow, $lastDataRow),
            );
            $picEnd = $row;
            $row++;
        }

        $salesStart = $dataStart;
        $salesEnd = $dataStart - 1;
        $row = $dataStart;
        foreach ($salesNames as $name) {
            $sheet->setCellValue("AP{$row}", mb_strtoupper($name));
            $this->setFormula(
                $sheet,
                "AQ{$row}",
                self::sumIfByNameFormula('Y', $name, 'AI', $firstDataRow, $lastDataRow),
            );
            $salesEnd = $row;
            $row++;
        }

        $totalRow = max($picEnd, $salesEnd) + 1;

        if ($picEnd >= $dataStart) {
            $sheet->setCellValue("AM{$totalRow}", 'TOTAL KOMISI PIC');
            $this->setFormula($sheet, "AN{$totalRow}", "=SUM(AN{$dataStart}:AN{$picEnd})");
            $this->styleSummaryBody($sheet, "AM{$dataStart}:AM{$picEnd}");
            $this->styleSummaryAmount($sheet, "AN{$dataStart}:AN{$totalRow}");
            $this->styleSummaryTotalRow($sheet, "AM{$totalRow}:AN{$totalRow}");
        }

        if ($salesEnd >= $salesStart) {
            $sheet->setCellValue("AP{$totalRow}", 'TOTAL KOMISI SALES');
            $this->setFormula($sheet, "AQ{$totalRow}", "=SUM(AQ{$salesStart}:AQ{$salesEnd})");
            $this->styleSummaryBody($sheet, "AP{$salesStart}:AP{$salesEnd}");
            $this->styleSummaryAmount($sheet, "AQ{$salesStart}:AQ{$totalRow}");
            $this->styleSummaryTotalRow($sheet, "AP{$totalRow}:AQ{$totalRow}");
        }

        foreach (['AM', 'AN', 'AO', 'AP', 'AQ'] as $column) {
            if (isset(self::COLUMN_WIDTHS[$column])) {
                $sheet->getColumnDimension($column)->setWidth(self::COLUMN_WIDTHS[$column]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function uniqueNamesFromColumn(Worksheet $sheet, string $column, int $firstRow, int $lastRow): array
    {
        $names = [];
        $seen = [];

        for ($row = $firstRow; $row <= $lastRow; $row++) {
            $name = trim((string) $sheet->getCell("{$column}{$row}")->getValue());
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        natcasesort($names);

        return array_values($names);
    }

    private static function sumIfByNameFormula(
        string $nameColumn,
        string $name,
        string $amountColumn,
        int $firstRow,
        int $lastRow,
    ): string {
        $escaped = str_replace('"', '""', $name);

        return "=SUMIF({$nameColumn}{$firstRow}:{$nameColumn}{$lastRow},\"{$escaped}\",{$amountColumn}{$firstRow}:{$amountColumn}{$lastRow})";
    }

    private function styleSummaryHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => self::BLACK]],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::SUMMARY_BLUE],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
        ]);
    }

    private function styleSummaryBody(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
        ]);
    }

    private function styleSummaryAmount(Worksheet $sheet, string $range): void
    {
        ExcelDecimalFormat::apply($sheet, $range, ExcelDecimalFormat::RUPIAH_COMPANY);
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_GENERAL,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
        ]);
    }

    private function styleSummaryTotalRow(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
        ]);
    }

    private function styleMergedCenter(Worksheet $sheet, string $range, bool $bold): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => $bold, 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    /**
     * @param list<int> $dataRows
     */
    private function applyTableStyles(Worksheet $sheet, int $firstDataRow, int $lastRow, array $dataRows): void
    {
        $this->applyColumnWidths($sheet);

        if ($lastRow < self::HEADER_ROW) {
            return;
        }

        $tableRange = 'A'.self::HEADER_ROW.':'.self::LAST_COLUMN.$lastRow;
        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_GREY],
                ],
            ],
            'font' => ['size' => 11],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        if ($dataRows === []) {
            return;
        }

        $formatStart = min($dataRows);
        $formatEnd = max($dataRows);

        // Semua non-harga: rata tengah + vertical middle.
        $sheet->getStyle("A{$formatStart}:K{$formatEnd}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->getStyle("A{$formatStart}:A{$formatEnd}")->applyFromArray([
            'numberFormat' => ['formatCode' => ExcelDecimalFormat::COUNT],
        ]);

        foreach ($dataRows as $row) {
            ExcelDecimalFormat::refreshOptionalDecimal($sheet, "G{$row}", CbmFormat::DECIMALS);
            ExcelDecimalFormat::refreshOptionalDecimal($sheet, "I{$row}", 0);
            ExcelDecimalFormat::refreshOptionalDecimal($sheet, "J{$row}", 2);
        }

        // Kolom harga: jangan center horizontal (biar format Rp), tapi vertical middle.
        foreach (['L', 'M', 'O', 'P', 'Q'] as $column) {
            ExcelDecimalFormat::apply($sheet, "{$column}{$formatStart}:{$column}{$formatEnd}", ExcelDecimalFormat::RUPIAH);
            $sheet->getStyle("{$column}{$formatStart}:{$column}{$formatEnd}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_GENERAL,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        $sheet->getStyle("N{$formatStart}:N{$formatEnd}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->getStyle("R{$formatStart}:R{$formatEnd}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        foreach (['S', 'T', 'U', 'V'] as $column) {
            ExcelDecimalFormat::apply($sheet, "{$column}{$formatStart}:{$column}{$formatEnd}", ExcelDecimalFormat::RUPIAH_TOTAL);
            $sheet->getStyle("{$column}{$formatStart}:{$column}{$formatEnd}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_GENERAL,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        $sheet->getStyle("W{$formatStart}:AC{$formatEnd}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        ExcelDecimalFormat::apply($sheet, "AB{$formatStart}:AB{$formatEnd}", 'd-mmm');

        // Re-assert navy/black headers after table-wide border/font pass.
        $this->applyHeaderStyles($sheet);
    }

    private function applyHeaderStyles(Worksheet $sheet): void
    {
        $mainHeaderRange = 'A'.self::HEADER_ROW.':'.self::LAST_MAIN_HEADER_COLUMN.self::HEADER_ROW;
        $sheet->getStyle($mainHeaderRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::NAVY],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $companyHeaderRange = self::FIRST_COMPANY_COLUMN.self::HEADER_ROW.':'.self::LAST_COLUMN.self::HEADER_ROW;
        $sheet->getStyle($companyHeaderRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::BLACK],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
    }

    private function applyCompanyColumnStyles(Worksheet $sheet, int $firstDataRow, int $lastDataRow): void
    {
        if ($lastDataRow < $firstDataRow) {
            return;
        }

        $range = self::FIRST_COMPANY_COLUMN.$firstDataRow.':'.self::LAST_COLUMN.$lastDataRow;
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::COMPANY_PINK],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // AH komisi % — tengah + percent format; kolom Rp perusahaan tidak di-center.
        ExcelDecimalFormat::apply(
            $sheet,
            "AH{$firstDataRow}:AH{$lastDataRow}",
            ExcelDecimalFormat::PERCENT,
        );
        $sheet->getStyle("AH{$firstDataRow}:AH{$lastDataRow}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        foreach (['AD', 'AE', 'AF', 'AG', 'AI', 'AJ'] as $column) {
            ExcelDecimalFormat::apply(
                $sheet,
                "{$column}{$firstDataRow}:{$column}{$lastDataRow}",
                ExcelDecimalFormat::RUPIAH_COMPANY,
            );
            $sheet->getStyle("{$column}{$firstDataRow}:{$column}{$lastDataRow}")->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_GENERAL,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }
    }

    private function applyTotalsRowStyles(Worksheet $sheet, int $row): void
    {
        $sheet->getStyle("AG{$row}:AJ{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::COMPANY_PINK],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        foreach (['AG', 'AI', 'AJ'] as $column) {
            ExcelDecimalFormat::apply($sheet, "{$column}{$row}", ExcelDecimalFormat::RUPIAH_COMPANY);
        }
    }

    private function applyColumnWidths(Worksheet $sheet): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /** Sembunyikan No & Tanggal Masuk; biarkan No Index (B) tampil di sebelah Marking. */
    private function hideColumnsAandC(Worksheet $sheet): void
    {
        foreach (['A', 'C'] as $column) {
            $dim = $sheet->getColumnDimension($column);
            $dim->setVisible(false);
            $dim->setCollapsed(true);
            $dim->setWidth(0);
        }
    }

    /**
     * @param array<string, mixed> $rowData
     */
    private function weightThresholdFromRow(array $rowData): float
    {
        return max(0, (float) ($rowData['weight_threshold_kg'] ?? 0));
    }

    /**
     * Total CBM marking: sum volume aktual dulu, lalu max dengan min charge (total-first).
     */
    private static function totalCbmFormula(int $start, int $end, float $minimumChargeCbm): string
    {
        $sum = "SUM(G{$start}:G{$end})";
        if ($minimumChargeCbm > 0) {
            return "=MAX({$sum},{$minimumChargeCbm})";
        }

        return "={$sum}";
    }

    /**
     * Overweight dicek dari Total CBM (H) × threshold (I) vs Total KG (K), bukan per baris G/J.
     */
    private static function keteranganFormula(int $totalRow, int $row): string
    {
        return "=IF(H{$totalRow}*I{$row}>=K{$totalRow},\"CBM\",\"CBM + Overweight -\"&K{$totalRow}-(H{$totalRow}*I{$row})&\" Kg\")";
    }

    /**
     * Lead marking: CBM baris + top-up min charge (H−SUM G) + overweight grup sekali.
     * Baris lain: hanya CBM baris (+ fee), supaya over tidak dobel.
     */
    private static function totalModalFormula(int $row, int $start, int $end, bool $isLead): string
    {
        if (! $isLead) {
            return "=L{$row}*G{$row}+Q{$row}";
        }

        return '=L'.$row.'*G'.$row
            .'+L'.$row.'*MAX(0,H'.$start.'-SUM(G'.$start.':G'.$end.'))'
            .'+IF(H'.$start.'*I'.$row.'<=K'.$start.',M'.$row.'*(K'.$start.'-(H'.$start.'*I'.$row.')),0)'
            .'+Q'.$row;
    }

    private static function totalJualFormula(int $row, int $start, int $end, bool $isLead): string
    {
        if (! $isLead) {
            return "=O{$row}*G{$row}+Q{$row}";
        }

        return '=O'.$row.'*G'.$row
            .'+O'.$row.'*MAX(0,H'.$start.'-SUM(G'.$start.':G'.$end.'))'
            .'+IF(H'.$start.'*I'.$row.'<=K'.$start.',P'.$row.'*(K'.$start.'-(H'.$start.'*I'.$row.')),0)'
            .'+Q'.$row;
    }

    private static function selisihLabaKotorFormula(int $row): string
    {
        return "=T{$row}-S{$row}";
    }

    private static function totalModalPerusahaanFormula(int $row, int $start, int $end, bool $isLead): string
    {
        // Max kg/CBM dari kolom I (isi Marking / barang), bukan angka tetap.
        if (! $isLead) {
            return "=AE{$row}*G{$row}+Q{$row}";
        }

        return '=AE'.$row.'*G'.$row
            .'+AE'.$row.'*MAX(0,H'.$start.'-SUM(G'.$start.':G'.$end.'))'
            .'+IF(H'.$start.'*I'.$row.'<=K'.$start.',M'.$row.'*(K'.$start.'-(H'.$start.'*I'.$row.')),0)'
            .'+Q'.$row;
    }

    private static function columnLetter(int $index): string
    {
        $index++;
        $letters = '';

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private function setFormula(Worksheet $sheet, string $coordinate, string $formula): void
    {
        $sheet->getCell($coordinate)->setValueExplicit($formula, DataType::TYPE_FORMULA);
    }
}
