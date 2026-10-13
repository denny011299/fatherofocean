<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Exports\OmsetSpreadsheetBuilder;
use App\Support\ExcelDecimalFormat;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PHPUnit\Framework\TestCase;

final class OmsetSpreadsheetBuilderTest extends TestCase
{
    public function test_sheet_title_and_headers_match_expected_layout(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        (new OmsetSpreadsheetBuilder())->build($sheet, $this->samplePayload());

        $this->assertSame('Omset', $sheet->getTitle());
        $this->assertSame('No', $sheet->getCell('A1')->getValue());
        $this->assertSame('No Index', $sheet->getCell('B1')->getValue());
        $this->assertSame('Marking', $sheet->getCell('D1')->getValue());
        $this->assertSame('CBM', $sheet->getCell('G1')->getValue());
        $this->assertSame('Total CBM', $sheet->getCell('H1')->getValue());
        $this->assertSame('Max CBM per marking', $sheet->getCell('I1')->getValue());
        $this->assertSame('KG', $sheet->getCell('J1')->getValue());
        $this->assertSame('Total KG', $sheet->getCell('K1')->getValue());
        $this->assertSame('Harga Modal CBM', $sheet->getCell('L1')->getValue());
        $this->assertSame('Total Modal', $sheet->getCell('S1')->getValue());
        $this->assertSame('Total Jual', $sheet->getCell('T1')->getValue());
        $this->assertSame('Grand Total bayar', $sheet->getCell('U1')->getValue());
        $this->assertSame('Selisih (Laba Kotor)', $sheet->getCell('V1')->getValue());
        $this->assertSame('Catatan', $sheet->getCell('W1')->getValue());
        $this->assertSame('PIC', $sheet->getCell('X1')->getValue());
        $this->assertSame('Sales', $sheet->getCell('Y1')->getValue());
        $this->assertSame('Pembayaran', $sheet->getCell('Z1')->getValue());
        $this->assertSame('Update', $sheet->getCell('AA1')->getValue());
        $this->assertSame('Loading', $sheet->getCell('AB1')->getValue());
        $this->assertSame('Partai', $sheet->getCell('AC1')->getValue());
        $this->assertSame('Profit Perusahaan', $sheet->getCell('AD1')->getValue());
        $this->assertSame('Modal Perusahaan', $sheet->getCell('AE1')->getValue());
        $this->assertSame('Total Modal Perusahaan', $sheet->getCell('AF1')->getValue());
        $this->assertSame('Komisi Perusahaan', $sheet->getCell('AG1')->getValue());
        $this->assertSame('Komisi Sales', $sheet->getCell('AH1')->getValue());
        $this->assertSame('Komisi Sales', $sheet->getCell('AI1')->getValue());
        $this->assertSame('Komisi PIC', $sheet->getCell('AJ1')->getValue());
        $this->assertFalse($sheet->getColumnDimension('A')->getVisible());
        $this->assertTrue($sheet->getColumnDimension('B')->getVisible());
        $this->assertFalse($sheet->getColumnDimension('C')->getVisible());
        $this->assertTrue($sheet->getColumnDimension('D')->getVisible());
    }

    public function test_header_fills_match_expected_navy_and_black(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        (new OmsetSpreadsheetBuilder())->build($sheet, $this->samplePayload());

        $this->assertSame(
            '0F172A',
            $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB(),
        );
        $this->assertSame(
            '0F172A',
            $sheet->getStyle('AC1')->getFill()->getStartColor()->getRGB(),
        );
        $this->assertSame(
            '000000',
            $sheet->getStyle('AD1')->getFill()->getStartColor()->getRGB(),
        );
        $this->assertSame(
            '000000',
            $sheet->getStyle('AJ1')->getFill()->getStartColor()->getRGB(),
        );
    }

    public function test_data_row_uses_expected_formulas_and_columns(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        (new OmsetSpreadsheetBuilder())->build($sheet, $this->samplePayload());

        $this->assertSame('0605.11', $sheet->getCell('B2')->getValue());
        $this->assertSame('EK/ON/GWSS', $sheet->getCell('D2')->getValue());
        $this->assertSame(0.856, $sheet->getCell('G2')->getCalculatedValue());
        $this->assertSame(500, $sheet->getCell('I2')->getCalculatedValue());
        $this->assertEquals(600, $sheet->getCell('J2')->getCalculatedValue());
        $this->assertSame(
            '=IF(H2*I2>=K2,"CBM","CBM + Overweight -"&K2-(H2*I2)&" Kg")',
            $sheet->getCell('N2')->getValue(),
        );
        $this->assertSame(
            '=L2*G2+L2*MAX(0,H2-SUM(G2:G3))+IF(H2*I2<=K2,M2*(K2-(H2*I2)),0)+Q2',
            $sheet->getCell('S2')->getValue(),
        );
        $this->assertSame(
            '=O2*G2+O2*MAX(0,H2-SUM(G2:G3))+IF(H2*I2<=K2,P2*(K2-(H2*I2)),0)+Q2',
            $sheet->getCell('T2')->getValue(),
        );
        $this->assertSame('=O3*G3+Q3', $sheet->getCell('T3')->getValue());
        $this->assertSame('=SUM(G2:G3)', $sheet->getCell('H2')->getValue());
        $this->assertSame('=SUM(J2:J3)', $sheet->getCell('K2')->getValue());
        $this->assertSame('=SUM(T2:T3)', $sheet->getCell('U2')->getValue());
        $this->assertSame('=T2-S2', $sheet->getCell('V2')->getValue());
        $this->assertSame(150_000.0, $sheet->getCell('AD2')->getCalculatedValue());
        $this->assertSame('=L2+AD2', $sheet->getCell('AE2')->getValue());
        $this->assertSame(
            '=AE2*G2+AE2*MAX(0,H2-SUM(G2:G3))+IF(H2*I2<=K2,M2*(K2-(H2*I2)),0)+Q2',
            $sheet->getCell('AF2')->getValue(),
        );
        $this->assertSame('=AE3*G3+Q3', $sheet->getCell('AF3')->getValue());
        $this->assertSame('=AD2*G2', $sheet->getCell('AG2')->getValue());
        $this->assertSame(0.025, $sheet->getCell('AH2')->getValue());
        $this->assertSame(
            ExcelDecimalFormat::PERCENT,
            $sheet->getStyle('AH2')->getNumberFormat()->getFormatCode(),
        );
        $this->assertSame('=(T2-Q2)*AH2', $sheet->getCell('AI2')->getValue());
        $this->assertSame('=T2-AF2-AI2', $sheet->getCell('AJ2')->getValue());
        $this->assertSame('David', $sheet->getCell('Y2')->getValue());
        $this->assertSame('Erich', $sheet->getCell('X2')->getValue()); // PIC from master user hierarchy
        $this->assertSame('Sudah Lunas', $sheet->getCell('Z2')->getValue());
        $this->assertSame('Bongkaran', $sheet->getCell('AA2')->getValue());
        $this->assertSame(46151.0, $sheet->getCell('AB2')->getCalculatedValue());
        $this->assertEquals('450', (string) $sheet->getCell('AC2')->getValue());
        $this->assertSame(
            'E6B9B8',
            $sheet->getStyle('AD2')->getFill()->getStartColor()->getRGB(),
        );
        $this->assertSame(Fill::FILL_SOLID, $sheet->getStyle('AJ2')->getFill()->getFillType());
        $this->assertSame(
            Alignment::VERTICAL_CENTER,
            $sheet->getStyle('E2')->getAlignment()->getVertical(),
        );
        $this->assertSame(
            Alignment::HORIZONTAL_CENTER,
            $sheet->getStyle('E2')->getAlignment()->getHorizontal(),
        );
        $this->assertSame(
            Alignment::VERTICAL_CENTER,
            $sheet->getStyle('L2')->getAlignment()->getVertical(),
        );
        $this->assertNotSame(
            Alignment::HORIZONTAL_CENTER,
            $sheet->getStyle('L2')->getAlignment()->getHorizontal(),
        );
    }

    public function test_ae_formula_uses_column_i_from_marking_not_hardcoded_500(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        $payload['groups'][0]['rows'][0]['weight_threshold_kg'] = 400;

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame(400, $sheet->getCell('I2')->getCalculatedValue());
        $this->assertSame(
            '=AE2*G2+AE2*MAX(0,H2-SUM(G2:G3))+IF(H2*I2<=K2,M2*(K2-(H2*I2)),0)+Q2',
            $sheet->getCell('AF2')->getValue(),
        );
        $this->assertStringNotContainsString('*500', (string) $sheet->getCell('AF2')->getValue());
    }

    public function test_commission_rate_uses_per_row_rate_from_user_management(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        $payload['groups'][0]['rows'][0]['commission_rate'] = 3.25;

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame(0.0325, $sheet->getCell('AH2')->getValue());
        $this->assertSame(
            ExcelDecimalFormat::PERCENT,
            $sheet->getStyle('AH2')->getNumberFormat()->getFormatCode(),
        );
        $this->assertIsFloat($sheet->getCell('AH2')->getValue());
        $this->assertSame('=(T2-Q2)*AH2', $sheet->getCell('AI2')->getValue());
    }

    public function test_company_loyalty_amount_from_row_fills_ad(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        $payload['groups'][0]['rows'][0]['company_loyalty_amount'] = 175_000;

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame(175_000.0, $sheet->getCell('AD2')->getCalculatedValue());
    }

    public function test_second_pic_group_resets_numbering_without_sub_headers(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        $payload['groups'][] = [
            'marketing_name' => 'Daniel',
            'sales_id' => 2,
            'commission_rate' => 2.5,
            'rows' => [
                [
                    'serial_no' => '0605.11',
                    'tanggal_masuk' => '15 Mei 2026',
                    'marking' => 'EK/ON/Coba',
                    'item' => 'Sepatu',
                    'kategori' => 'Sepatu Non Brand',
                    'cbm' => 2.5,
                    'kg' => 600.0,
                    'weight_threshold_kg' => 500,
                    'modal_cbm_price' => 3_950_000,
                    'modal_kg_price' => 7_400,
                    'sell_cbm_price' => 4_200_000,
                    'sell_kg_price' => 9_000,
                    'payment_status' => 'Sudah Lunas',
                    'status_update' => 'Bongkaran',
                ],
            ],
        ];

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame(1, $sheet->getCell('A2')->getCalculatedValue());
        $this->assertSame('David', $sheet->getCell('Y2')->getValue());
        $this->assertSame(1, $sheet->getCell('A4')->getCalculatedValue());
        $this->assertSame('Daniel', $sheet->getCell('Y4')->getValue());
        $this->assertSame('EK/ON/Coba', $sheet->getCell('D4')->getValue());
        $this->assertSame(2.5, $sheet->getCell('H4')->getCalculatedValue());
    }

    public function test_cbm_column_shows_actual_volume_total_cbm_applies_min_charge(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        $payload['groups'][0]['rows'] = [
            [
                'serial_no' => '0605.11',
                'tanggal_masuk' => '15 Mei 2026',
                'marking' => 'EK/ON/GWSS',
                'item' => 'Sepatu',
                'kategori' => 'Sepatu Non Brand',
                'cbm' => 0.027,
                'cbm_actual' => 0.027,
                'minimum_charge_cbm' => 0.1,
                'kg' => 10.0,
                'weight_threshold_kg' => 500,
                'modal_cbm_price' => 3_950_000,
                'sell_cbm_price' => 4_200_000,
                'payment_status' => 'Sudah Lunas',
                'status_update' => 'Bongkaran',
            ],
        ];

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame(0.027, $sheet->getCell('G2')->getCalculatedValue());
        $this->assertSame(0.1, $sheet->getCell('H2')->getCalculatedValue());
        $this->assertSame(
            ExcelDecimalFormat::DIMENSION,
            $sheet->getStyle('I2')->getNumberFormat()->getFormatCode(),
        );
        $this->assertSame(500, $sheet->getCell('I2')->getValue());
    }

    public function test_overweight_keterangan_uses_marking_totals_not_per_row(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        // Baris 1 over sendiri (100 > 0.1*500), tapi total marking aman (110 <= 1.2*500).
        $payload['groups'][0]['rows'] = [
            [
                'serial_no' => 'P-1',
                'tanggal_masuk' => '15 Mei 2026',
                'marking' => 'EK/CN/ADR/UBL',
                'item' => 'Lunch bag',
                'kategori' => 'Umum',
                'cbm' => 0.1,
                'cbm_actual' => 0.1,
                'minimum_charge_cbm' => 0.1,
                'kg' => 100.0,
                'weight_threshold_kg' => 500,
                'modal_cbm_price' => 5_000_000,
                'modal_kg_price' => 10_000,
                'sell_cbm_price' => 5_000_000,
                'sell_kg_price' => 10_000,
                'payment_status' => 'belum lunas',
                'status_update' => 'Inbound',
            ],
            [
                'serial_no' => 'P-2',
                'marking' => 'EK/CN/ADR/UBL',
                'cbm' => 1.1,
                'cbm_actual' => 1.1,
                'minimum_charge_cbm' => 0.1,
                'kg' => 10.0,
                'weight_threshold_kg' => 500,
                'modal_cbm_price' => 5_000_000,
                'sell_cbm_price' => 5_000_000,
                'payment_status' => 'belum lunas',
                'status_update' => 'Inbound',
            ],
        ];

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame('=MAX(SUM(G2:G3),0.1)', $sheet->getCell('H2')->getValue());
        $this->assertEqualsWithDelta(1.2, (float) $sheet->getCell('H2')->getCalculatedValue(), 0.0001);
        $this->assertEqualsWithDelta(110.0, (float) $sheet->getCell('K2')->getCalculatedValue(), 0.0001);
        $this->assertSame(
            '=IF(H2*I2>=K2,"CBM","CBM + Overweight -"&K2-(H2*I2)&" Kg")',
            $sheet->getCell('N2')->getValue(),
        );
        $this->assertSame('CBM', $sheet->getCell('N2')->getCalculatedValue());
        $this->assertSame('=O3*G3+Q3', $sheet->getCell('T3')->getValue());
    }

    public function test_cbm_and_kg_whole_numbers_omit_trailing_decimal_comma(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        $payload['groups'][0]['rows'][0]['cbm'] = 1.0;
        $payload['groups'][0]['rows'][0]['kg'] = 11.0;
        $payload['groups'][0]['rows'][0]['weight_threshold_kg'] = 500;
        $payload['groups'][0]['rows'][1]['kg'] = 9.8;

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame(ExcelDecimalFormat::DIMENSION, $sheet->getStyle('G2')->getNumberFormat()->getFormatCode());
        $this->assertSame(1, $sheet->getCell('G2')->getValue());
        $this->assertSame(ExcelDecimalFormat::DIMENSION, $sheet->getStyle('I2')->getNumberFormat()->getFormatCode());
        $this->assertSame(500, $sheet->getCell('I2')->getValue());
        $this->assertSame(ExcelDecimalFormat::DIMENSION, $sheet->getStyle('J2')->getNumberFormat()->getFormatCode());
        $this->assertSame(11, $sheet->getCell('J2')->getValue());
        $this->assertSame(ExcelDecimalFormat::WEIGHT, $sheet->getStyle('J3')->getNumberFormat()->getFormatCode());
        $this->assertSame(9.8, $sheet->getCell('J3')->getCalculatedValue());
    }

    public function test_cbm_column_uses_optional_decimal_format_with_leading_zero(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        (new OmsetSpreadsheetBuilder())->build($sheet, $this->samplePayload());

        $this->assertSame(
            ExcelDecimalFormat::CBM,
            $sheet->getStyle('G3')->getNumberFormat()->getFormatCode(),
        );
        $this->assertSame(0.409, $sheet->getCell('G3')->getCalculatedValue());
    }

    public function test_last_row_sums_company_and_sales_commission_columns(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        (new OmsetSpreadsheetBuilder())->build($sheet, $this->samplePayload());

        $this->assertSame('=SUM(AG1:AG3)', $sheet->getCell('AG4')->getValue());
        $this->assertSame('=SUM(AI1:AI3)', $sheet->getCell('AI4')->getValue());
        $this->assertSame('=SUM(AJ1:AJ3)', $sheet->getCell('AJ4')->getValue());
    }

    public function test_commission_summary_tables_list_pic_and_sales_totals(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        $payload['groups'][] = [
            'marketing_name' => 'Daniel',
            'sales_id' => 2,
            'commission_rate' => 2.5,
            'rows' => [
                [
                    'serial_no' => '0605.11',
                    'tanggal_masuk' => '15 Mei 2026',
                    'marking' => 'EK/ON/Coba',
                    'item' => 'Sepatu',
                    'kategori' => 'Sepatu Non Brand',
                    'cbm' => 2.5,
                    'kg' => 600.0,
                    'weight_threshold_kg' => 500,
                    'modal_cbm_price' => 3_950_000,
                    'sell_cbm_price' => 4_200_000,
                    'marketing_staff' => 'Daniel',
                    'sales_name' => 'Barnett',
                    'payment_status' => 'Sudah Lunas',
                    'status_update' => 'Bongkaran',
                ],
            ],
        ];

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame('KOMISI PIC', $sheet->getCell('AM2')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('AN2')->getValue());
        $this->assertSame('KOMISI SALES', $sheet->getCell('AP2')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('AQ2')->getValue());

        // PIC dari kolom X (master user / marketing_staff), bukan nama sales.
        $this->assertSame('DANIEL', $sheet->getCell('AM3')->getValue());
        $this->assertSame('=SUMIF(X2:X4,"Daniel",AJ2:AJ4)', $sheet->getCell('AN3')->getValue());
        $this->assertSame('ERICH', $sheet->getCell('AM4')->getValue());
        $this->assertSame('=SUMIF(X2:X4,"Erich",AJ2:AJ4)', $sheet->getCell('AN4')->getValue());

        // Sales dari kolom Y.
        $this->assertSame('BARNETT', $sheet->getCell('AP3')->getValue());
        $this->assertSame('=SUMIF(Y2:Y4,"Barnett",AI2:AI4)', $sheet->getCell('AQ3')->getValue());
        $this->assertSame('DAVID', $sheet->getCell('AP4')->getValue());
        $this->assertSame('=SUMIF(Y2:Y4,"David",AI2:AI4)', $sheet->getCell('AQ4')->getValue());

        $this->assertSame('TOTAL KOMISI PIC', $sheet->getCell('AM5')->getValue());
        $this->assertSame('=SUM(AN3:AN4)', $sheet->getCell('AN5')->getValue());
        $this->assertSame('TOTAL KOMISI SALES', $sheet->getCell('AP5')->getValue());
        $this->assertSame('=SUM(AQ3:AQ4)', $sheet->getCell('AQ5')->getValue());
        $this->assertSame(
            '9BC2E6',
            $sheet->getStyle('AM2')->getFill()->getStartColor()->getRGB(),
        );
    }

    public function test_catatan_filled_when_notes_present(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $payload = $this->samplePayload();
        $payload['groups'][0]['rows'][0]['catatan'] = "sienny ini bayar\nkurang 400 rupiah,\ntapi sudah deal\ndengan marketing";

        (new OmsetSpreadsheetBuilder())->build($sheet, $payload);

        $this->assertSame(
            "sienny ini bayar\nkurang 400 rupiah,\ntapi sudah deal\ndengan marketing",
            $sheet->getCell('W2')->getValue(),
        );
        $this->assertTrue($sheet->getStyle('W2')->getAlignment()->getWrapText());
        $this->assertGreaterThanOrEqual(70.0, (float) $sheet->getRowDimension(2)->getRowHeight());
    }

    /** @return array<string, mixed> */
    private function samplePayload(): array
    {
        return [
            'groups' => [
                [
                    'marketing_name' => 'David',
                    'sales_id' => 1,
                    'commission_rate' => 2.5,
                    'rows' => [
                        [
                            'serial_no' => '0605.11',
                            'tanggal_masuk' => '15 Mei 2026',
                            'tanggal_masuk_excel' => 46151.0,
                            'marking' => 'EK/ON/GWSS',
                            'item' => 'Sepatu',
                            'kategori' => 'Sepatu Non Brand',
                            'cbm' => 0.856,
                            'kg' => 600.0,
                            'weight_threshold_kg' => 500,
                            'modal_cbm_price' => 3_950_000,
                            'modal_kg_price' => 7_400,
                            'sell_cbm_price' => 4_200_000,
                            'sell_kg_price' => 9_000,
                            'additional_fee' => 1_000_000,
                            'additional_fee_note' => 'biaya forklift',
                            'commission_rate' => 2.5,
                            'company_loyalty_amount' => 150_000,
                            'marketing_staff' => 'Erich',
                            'payment_status' => 'Sudah Lunas',
                            'status_update' => 'Bongkaran',
                            'partai' => '450',
                        ],
                        [
                            'serial_no' => '0805.02',
                            'marking' => 'EK/ON/GWSS',
                            'cbm' => 0.409,
                            'weight_threshold_kg' => 500,
                            'modal_cbm_price' => 3_950_000,
                            'sell_cbm_price' => 4_200_000,
                            'commission_rate' => 2.5,
                            'company_loyalty_amount' => 150_000,
                            'payment_status' => 'Sudah Lunas',
                            'status_update' => 'Bongkaran',
                        ],
                    ],
                ],
            ],
            'partai_label' => '450',
            'company_fixed_amount' => 150_000,
            'company_name' => 'Oceanlink',
        ];
    }
}
