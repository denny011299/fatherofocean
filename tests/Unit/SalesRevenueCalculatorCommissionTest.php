<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\User;
use App\Support\SalesCommissionSettings;
use App\Support\SalesRevenueCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SalesRevenueCalculatorCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
    }

    public function test_estimated_amount_uses_sales_override_rate_on_full_omset(): void
    {
        SalesCommissionSettings::update(2.5);

        $sales = User::factory()->create([
            'sales_commission_rate_override' => 5.0,
        ]);
        $sales->assignRole('sales');

        $owner = User::factory()->create();

        $marking = Marking::query()->create([
            'user_id' => $owner->id,
            'sales_id' => $sales->id,
            'code' => 'OVERRIDE01',
            'customer_name' => 'Test',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-OVERRIDE-1',
            'marking' => $marking->code,
            'price_per_cbm' => 1_000_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'created_by' => $owner->id,
        ]);

        $omset = SalesRevenueCalculator::billableBase($barang);
        $expected = round($omset * 5.0 / 100, 0);

        $this->assertSame($expected, SalesRevenueCalculator::estimatedAmount($barang));
        $snap = SalesRevenueCalculator::snapshotForBarang($barang);
        $this->assertNotNull($snap);
        $this->assertSame(5.0, $snap['commission_rate']);
        $this->assertSame($expected, $snap['estimated_amount']);
    }
}
