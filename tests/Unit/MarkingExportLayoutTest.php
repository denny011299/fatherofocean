<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exports\MarkingExport;
use App\Http\Controllers\MarkingController;
use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterPricingItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ReflectionMethod;
use Tests\TestCase;

final class MarkingExportLayoutTest extends TestCase
{
    public function test_xlsx_headers_match_template_with_prices(): void
    {
        $rows = new Collection([
            [
                'Surabaya', 'David', 'BS/5758/SEA', 'Joe Kiem Sing',
                'SAN DIEGO M6/49 PAKUWON CITY SURABAYA, 112',
                '89606161916', '', 'Never shipped', 7, 0.1,
                500.0, '2026-05-19', 'Umum', 'Rp 5,500,000', "Alat Tulis\nSabun Mandi\nSisir",
                '', '', '',
            ],
        ]);

        $path = $this->exportToTempFile(new MarkingExport($rows, true));
        $sheet = IOFactory::load($path)->getSheetByName('Marking');

        $this->assertSame('Branch', $sheet->getCell('A1')->getValue());
        $this->assertSame('Marking', $sheet->getCell('C1')->getValue());
        $this->assertSame('Phone', $sheet->getCell('F1')->getValue());
        $this->assertSame('Contact 1', $sheet->getCell('F2')->getValue());
        $this->assertSame('Contact 2', $sheet->getCell('G2')->getValue());
        $this->assertSame('Pricing', $sheet->getCell('M1')->getValue());
        $this->assertSame('Kategori', $sheet->getCell('M2')->getValue());
        $this->assertSame('Harga', $sheet->getCell('N2')->getValue());
        $this->assertSame('Item', $sheet->getCell('O2')->getValue());
        $this->assertSame('Kelengkapan Data Lain', $sheet->getCell('R1')->getValue());

        $merged = $sheet->getMergeCells();
        $this->assertArrayHasKey('F1:G1', $merged);
        $this->assertArrayHasKey('M1:O1', $merged);
        $this->assertArrayHasKey('A1:A2', $merged);

        $this->assertSame('Surabaya', $sheet->getCell('A3')->getValue());
        $this->assertSame('89606161916', $sheet->getCell('F3')->getValue());
        $this->assertSame('Umum', $sheet->getCell('M3')->getValue());
        $this->assertSame('Rp 5,500,000', $sheet->getCell('N3')->getValue());
        $this->assertSame("Alat Tulis\nSabun Mandi\nSisir", $sheet->getCell('O3')->getValue());
        $this->assertSame('BDD7EE', $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB());

        @unlink($path);
    }

    public function test_xlsx_headers_without_prices_omit_pricing_block(): void
    {
        $rows = new Collection([
            ['Surabaya', 'David', 'BS/1', 'Cust', 'Addr', '081', '082', 'Never shipped', 7, 0.1, '', '', ''],
        ]);

        $path = $this->exportToTempFile(new MarkingExport($rows, false));
        $sheet = IOFactory::load($path)->getSheetByName('Marking');

        $this->assertSame('NIK', $sheet->getCell('K1')->getValue());
        $this->assertSame('Kelengkapan Data Lain', $sheet->getCell('M1')->getValue());
        $this->assertNotContains('M1:O1', array_keys($sheet->getMergeCells()));

        @unlink($path);
    }

    public function test_export_endpoint_always_includes_pricing_columns(): void
    {
        $controller = app(MarkingController::class);
        $method = new ReflectionMethod($controller, 'markingToExportRows');
        $method->setAccessible(true);

        $marking = $this->makeMarking([
            'effective_start' => \Illuminate\Support\Carbon::parse('2026-05-19'),
            'max_weight_kg' => 500,
            'invoice_due_days' => 7,
            'minimum_charge' => 0.1,
            'customer_name' => 'Joe',
            'phone_hp' => '081',
            'address_1' => 'Addr',
        ], new Collection([
            $this->makePricing(10, '5500000', 'Umum', "Alat Tulis\nSabun Mandi\nSisir"),
        ]));
        $marking->setRelation('branch', null);
        $marking->setRelation('sales', null);
        $marking->setAttribute('address_2', null);
        $marking->setAttribute('phone_02', null);
        $marking->setAttribute('nik', null);
        $marking->setAttribute('nik_name', null);

        // Even when caller asks for showPrices=false, export rows used by endpoint force prices.
        $withPrices = $method->invoke($controller, $marking, true);
        $this->assertCount(1, $withPrices);
        $this->assertSame(500.0, $withPrices[0][10]);
        $this->assertSame('2026-05-19', $withPrices[0][11]);
        $this->assertSame('Umum', $withPrices[0][12]);
        $this->assertSame('Rp 5,500,000', $withPrices[0][13]);
        $this->assertSame("Alat Tulis\nSabun Mandi\nSisir", $withPrices[0][14]);
    }

    public function test_pricing_groups_include_rows_before_effective_start(): void
    {
        $marking = $this->makeMarking(['effective_start' => '2099-01-01'], new Collection([
            $this->makePricing(10, '5500000', 'Umum', 'Alat Tulis'),
        ]));

        $groups = $this->invokeExportPricingGroups($marking);

        $this->assertCount(1, $groups);
        $this->assertSame('Umum', $groups[0]['kategori']);
        $this->assertSame('Alat Tulis', $groups[0]['item']);
    }

    public function test_pricing_groups_kategori_harga_and_item_match_template(): void
    {
        $marking = $this->makeMarking(['effective_start' => '2026-05-01'], new Collection([
            $this->makePricing(10, '5500000', 'Umum'),
            $this->makePricing(11, '5500000', 'Alat Tulis'),
            $this->makePricing(12, '5500000', 'Sabun Mandi'),
            $this->makePricing(13, '5500000', 'Sisir'),
        ]));

        $groups = $this->invokeExportPricingGroups($marking);

        $this->assertCount(1, $groups);
        $this->assertSame('Umum', $groups[0]['kategori']);
        $this->assertSame('Rp 5,500,000', $groups[0]['harga']);
        $this->assertSame("Alat Tulis\nSabun Mandi\nSisir", $groups[0]['item']);
    }

    public function test_pricing_groups_prefer_item_name_over_kategori_fallback(): void
    {
        $marking = $this->makeMarking(['effective_start' => '2026-05-01'], new Collection([
            $this->makePricing(10, '5500000', 'Umum', "Alat Tulis\nSabun Mandi\nSisir"),
        ]));

        $groups = $this->invokeExportPricingGroups($marking);

        $this->assertCount(1, $groups);
        $this->assertSame('Umum', $groups[0]['kategori']);
        $this->assertSame('Rp 5,500,000', $groups[0]['harga']);
        $this->assertSame("Alat Tulis\nSabun Mandi\nSisir", $groups[0]['item']);
    }

    public function test_pricing_groups_split_when_volume_prices_differ(): void
    {
        $marking = $this->makeMarking(['effective_start' => '2026-05-01'], new Collection([
            $this->makePricing(10, '5500000', 'Umum'),
            $this->makePricing(11, '6000000', 'Lartas'),
        ]));

        $groups = $this->invokeExportPricingGroups($marking);

        $this->assertCount(2, $groups);
        $this->assertSame('Umum', $groups[0]['kategori']);
        $this->assertSame('Rp 5,500,000', $groups[0]['harga']);
        $this->assertSame('Umum', $groups[0]['item']);
        $this->assertSame('Lartas', $groups[1]['kategori']);
        $this->assertSame('Rp 6,000,000', $groups[1]['harga']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeMarking(array $attributes, Collection $pricings): Marking
    {
        $marking = (new \ReflectionClass(Marking::class))->newInstanceWithoutConstructor();
        foreach ($attributes as $key => $value) {
            $marking->setAttribute($key, $value);
        }
        $marking->setRelation('pricings', $pricings);

        return $marking;
    }

    private function makePricing(int $id, string $volumePrice, string $itemName, ?string $customItemName = null): MarkingPricing
    {
        $item = new MasterPricingItem;
        $item->setAttribute('name', $itemName);

        $pricing = (new \ReflectionClass(MarkingPricing::class))->newInstanceWithoutConstructor();
        $pricing->setAttribute('id', $id);
        $pricing->setAttribute('volume_price', $volumePrice);
        $pricing->setAttribute('item_name', $customItemName);
        $pricing->setRelation('pricingItem', $item);

        return $pricing;
    }

    /** @return list<array{kategori: string, harga: string, item: string}> */
    private function invokeExportPricingGroups(Marking $marking): array
    {
        $controller = app(MarkingController::class);
        $method = new ReflectionMethod($controller, 'exportPricingGroups');
        $method->setAccessible(true);

        return $method->invoke($controller, $marking);
    }

    private function exportToTempFile(MarkingExport $export): string
    {
        $path = storage_path('app/testing/'.uniqid('marking-export-', true).'.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));

        return $path;
    }
}
