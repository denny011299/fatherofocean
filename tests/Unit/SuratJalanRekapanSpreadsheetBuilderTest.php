<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Exports\SuratJalanRekapanSpreadsheetBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class SuratJalanRekapanSpreadsheetBuilderTest extends TestCase
{
    public function test_headers_and_title_match_rekapan_pdf_layout(): void
    {
        $sheet = (new SuratJalanRekapanSpreadsheetBuilder())->build(
            $this->sampleRows(),
            'Container 538 · MSCU1234567',
        )->getActiveSheet();

        $this->assertSame('Rekapan Surat Jalan — Container 538 · MSCU1234567', $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString('Container 538', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('No Urut', $sheet->getCell('A3')->getValue());
        $this->assertSame('Marking', $sheet->getCell('B3')->getValue());
        $this->assertSame('Item', $sheet->getCell('C3')->getValue());
        $this->assertSame('Coly', $sheet->getCell('D3')->getValue());
        $this->assertSame('Total Coly', $sheet->getCell('E3')->getValue());
        $this->assertSame('Total CBM', $sheet->getCell('H3')->getValue());
        $this->assertSame('Total KG', $sheet->getCell('I3')->getValue());
        $this->assertSame('Telp', $sheet->getCell('M3')->getValue());
        $this->assertSame('Notes', $sheet->getCell('O3')->getValue());
    }

    public function test_data_rows_match_rekapan_pdf_values(): void
    {
        $sheet = (new SuratJalanRekapanSpreadsheetBuilder())->build($this->sampleRows())->getActiveSheet();

        $this->assertSame('2306.34', $sheet->getCell('A4')->getValue());
        $this->assertSame('EK/ON/777HK', $sheet->getCell('B4')->getValue());
        $this->assertSame('Pompa Udara (Aerator) Kolam Ikan atau Akuarium', $sheet->getCell('C4')->getValue());
        $this->assertSame('34', $sheet->getCell('D4')->getValue());
        $this->assertSame('34', $sheet->getCell('E4')->getValue());
        $this->assertEqualsWithDelta(1.393, $sheet->getCell('F4')->getCalculatedValue(), 0.0001);
        $this->assertSame('B 1234 AB', $sheet->getCell('J4')->getValue());
        $this->assertSame('Budi', $sheet->getCell('K4')->getValue());
        $this->assertSame('0822-4444-9930', $sheet->getCell('M4')->getValue());
        $this->assertStringContainsString('Jl. Raya Lontar', (string) $sheet->getCell('N4')->getValue());
    }

    public function test_empty_rows_show_placeholder_message(): void
    {
        $sheet = (new SuratJalanRekapanSpreadsheetBuilder())->build([])->getActiveSheet();

        $this->assertSame('Belum ada data surat jalan.', $sheet->getCell('A4')->getValue());
    }

    public function test_merges_coly_and_address_for_same_marking_and_address(): void
    {
        $rows = [
            [
                'show_group' => true,
                'merge_group_key' => 'ek/on/aluvly22|addr:grand pakuwon',
                'no_urut' => '2506.2',
                'marking' => 'EK/ON/ALUVLY22',
                'item' => 'Sepatu',
                'coly' => '19',
                'coly_raw' => 19,
                'total_coly' => '65',
                'cbm' => '7.624',
                'cbm_raw' => 7.624,
                'kg' => '',
                'kg_raw' => 0.0,
                'total_cbm' => '21.436',
                'total_kg' => '',
                'truck_plate' => '',
                'driver_name' => '',
                'driver_phone' => '',
                'telp' => '0811-1111-1111',
                'alamat' => 'Grand Pakuwon, Adelaide JF6 No. 39',
                'notes' => '',
            ],
            [
                'merge_group_key' => 'ek/on/aluvly22|addr:grand pakuwon',
                'no_urut' => '2506.3',
                'marking' => 'EK/ON/ALUVLY22',
                'item' => 'School Bag',
                'coly' => '23',
                'coly_raw' => 23,
                'total_coly' => '65',
                'cbm' => '5.575',
                'cbm_raw' => 5.575,
                'kg' => '',
                'kg_raw' => 0.0,
                'total_cbm' => '21.436',
                'total_kg' => '',
                'telp' => '0811-1111-1111',
                'alamat' => 'Grand Pakuwon, Adelaide JF6 No. 39',
                'notes' => '',
            ],
            [
                'merge_group_key' => 'ek/on/aluvly22|addr:grand pakuwon',
                'no_urut' => '2506.4',
                'marking' => 'EK/ON/ALUVLY22',
                'item' => 'School Bag 2',
                'coly' => '23',
                'coly_raw' => 23,
                'total_coly' => '65',
                'cbm' => '5.567',
                'cbm_raw' => 5.567,
                'kg' => '',
                'kg_raw' => 0.0,
                'total_cbm' => '21.436',
                'total_kg' => '',
                'telp' => '0811-1111-1111',
                'alamat' => 'Grand Pakuwon, Adelaide JF6 No. 39',
                'notes' => '',
            ],
        ];

        $sheet = (new SuratJalanRekapanSpreadsheetBuilder())->build($rows)->getActiveSheet();

        $this->assertSame('19', $sheet->getCell('D4')->getValue());
        $this->assertSame('23', $sheet->getCell('D5')->getValue());
        $this->assertSame('23', $sheet->getCell('D6')->getValue());
        $this->assertSame('65', $sheet->getCell('E4')->getValue());
        $mergedRanges = array_keys($sheet->getMergeCells());
        $this->assertContains('E4:E6', $mergedRanges);
        $this->assertContains('M4:M6', $mergedRanges);
        $this->assertContains('N4:N6', $mergedRanges);
        $this->assertStringContainsString('Grand Pakuwon', (string) $sheet->getCell('N4')->getValue());
    }

    public function test_split_admin_groups_do_not_merge_even_with_same_address_text(): void
    {
        $rows = [
            [
                'merge_group_key' => 'ek/on/test|split:0',
                'marking' => 'EK/ON/TEST',
                'item' => 'Item A',
                'coly' => '10',
                'coly_raw' => 10,
                'total_coly' => '10',
                'alamat' => 'Alamat Sama',
                'cbm' => '1',
                'cbm_raw' => 1.0,
                'kg' => '',
                'kg_raw' => 0.0,
                'total_cbm' => '1',
                'total_kg' => '',
                'no_urut' => '1',
                'notes' => '',
                'truck_plate' => '',
                'driver_name' => '',
                'driver_phone' => '',
                'telp' => '0812',
            ],
            [
                'merge_group_key' => 'ek/on/test|split:1',
                'marking' => 'EK/ON/TEST',
                'item' => 'Item B',
                'coly' => '12',
                'coly_raw' => 12,
                'total_coly' => '12',
                'alamat' => 'Alamat Sama',
                'cbm' => '2',
                'cbm_raw' => 2.0,
                'kg' => '',
                'kg_raw' => 0.0,
                'total_cbm' => '2',
                'total_kg' => '',
                'no_urut' => '2',
                'notes' => '',
                'truck_plate' => '',
                'driver_name' => '',
                'driver_phone' => '',
                'telp' => '0813',
            ],
        ];

        $sheet = (new SuratJalanRekapanSpreadsheetBuilder())->build($rows)->getActiveSheet();

        $this->assertSame('10', $sheet->getCell('D4')->getValue());
        $this->assertSame('12', $sheet->getCell('D5')->getValue());
        $this->assertSame('10', $sheet->getCell('E4')->getValue());
        $this->assertSame('12', $sheet->getCell('E5')->getValue());
        $mergedRanges = array_keys($sheet->getMergeCells());
        $this->assertNotContains('E4:E5', $mergedRanges);
        $this->assertNotContains('N4:N5', $mergedRanges);
    }

    /** @return list<array<string, mixed>> */
    private function sampleRows(): array
    {
        return [
            [
                'show_group' => true,
                'no_urut' => '2306.34',
                'marking' => 'EK/ON/777HK',
                'item' => 'Pompa Udara (Aerator) Kolam Ikan atau Akuarium',
                'coly' => '34',
                'coly_raw' => 34,
                'total_coly' => '34',
                'cbm' => '1.393',
                'kg' => '',
                'cbm_raw' => 1.393,
                'kg_raw' => 0.0,
                'total_cbm' => '1.393',
                'total_kg' => '',
                'truck_plate' => 'B 1234 AB',
                'driver_name' => 'Budi',
                'driver_phone' => '08123456789',
                'telp' => '0822-4444-9930',
                'alamat' => 'Jl. Raya Lontar 109A Sby (ruko biru, 3 rumah dr kuburan ke arah timur)',
                'notes' => '',
                'merge_group_key' => 'ek/on/777hk|addr:jl. raya lontar',
            ],
        ];
    }
}
