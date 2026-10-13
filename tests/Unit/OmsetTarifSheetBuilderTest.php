<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Exports\OmsetTarifSheetBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class OmsetTarifSheetBuilderTest extends TestCase
{
    public function test_tarif_sheet_lists_category_modal_and_profit_rates(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        (new OmsetTarifSheetBuilder())->build($sheet, [
            'company_fixed_amount' => 150_000,
            'category_reference' => [
                [
                    'kategori' => 'Lartas Berat',
                    'modal_cbm' => 6_150_000,
                    'modal_kg' => 12_000,
                    'profit_per_shipment' => 200_000,
                    'profit_is_fallback' => false,
                ],
                [
                    'kategori' => 'Umum',
                    'modal_cbm' => 5_150_000,
                    'modal_kg' => 10_000,
                    'profit_per_shipment' => 150_000,
                    'profit_is_fallback' => true,
                ],
            ],
        ]);

        $this->assertSame('Tarif Kategori', $sheet->getTitle());
        $this->assertSame('Kategori / Tipe Barang', $sheet->getCell('A5')->getValue());
        $this->assertSame('Lartas Berat', $sheet->getCell('A6')->getValue());
        $this->assertEquals(6_150_000, $sheet->getCell('B6')->getCalculatedValue());
        $this->assertEquals(12_000, $sheet->getCell('C6')->getCalculatedValue());
        $this->assertEquals(200_000, $sheet->getCell('D6')->getCalculatedValue());
        $this->assertSame('Umum', $sheet->getCell('A7')->getValue());
        $this->assertSame('Pakai default umum', $sheet->getCell('E7')->getValue());
        $this->assertStringContainsString('150.000', (string) $sheet->getCell('A3')->getValue());
    }
}
