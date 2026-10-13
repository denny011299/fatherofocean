<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\User;
use App\Support\CompanyRevenueSettings;
use App\Support\SalesCommissionSettings;
use App\Support\SalesRevenueCalculator;
use App\Support\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ShipmentEarningsBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
    }

    public function test_sales_commission_from_full_omset_not_after_company_deduction(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_FIXED_AMOUNT, '150000');
        SalesCommissionSettings::update(2.5);

        $sales = User::factory()->create();
        $sales->assignRole('sales');
        $owner = User::factory()->create();
        $owner->assignRole('supervisor');

        $marking = Marking::query()->create([
            'user_id' => $owner->id,
            'sales_id' => $sales->id,
            'code' => 'BRKDN01',
            'customer_name' => 'Test',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-BRK-1',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'cost_price_per_cbm' => 5_750_000,
            'company_loyalty_amount' => 150_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'created_by' => $owner->id,
        ]);

        $breakdown = SalesRevenueCalculator::earningsBreakdown($barang);
        $this->assertNotNull($breakdown);
        $this->assertSame(150_000.0, $breakdown['company_profit']);
        $this->assertSame(6_000_000.0, $breakdown['sales_commission_base']);
        $this->assertSame(150_000.0, $breakdown['sales_commission']);
        $this->assertSame(2.5, $breakdown['commission_rate']);
        $this->assertSame(250_000.0, $breakdown['markup']);
        $this->assertSame(100_000.0, $breakdown['supervisor_amount']);
    }

    public function test_user_simulation_per_cbm_with_per_shipment_loyalty(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_FIXED_AMOUNT, '150000');
        SalesCommissionSettings::update(2.5);

        $sales = User::factory()->create();
        $sales->assignRole('sales');
        $owner = User::factory()->create();
        $owner->assignRole('supervisor');

        $marking = Marking::query()->create([
            'user_id' => $owner->id,
            'sales_id' => $sales->id,
            'code' => 'SIM01',
            'customer_name' => 'Simulation',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-SIM-1',
            'marking' => $marking->code,
            'price_per_cbm' => 5_400_000,
            'cost_price_per_cbm' => 5_150_000,
            'company_loyalty_amount' => 150_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'created_by' => $owner->id,
        ]);

        $breakdown = SalesRevenueCalculator::earningsBreakdown($barang);
        $this->assertNotNull($breakdown);

        // Komisi: 5.400.000 × 2,5% = 135.000 / CBM
        $this->assertSame(135_000.0, $breakdown['sales_commission']);
        // Kas admin: 150.000 / pengiriman (bukan per CBM)
        $this->assertSame(150_000.0, $breakdown['company_profit']);
        // PIC: (5.400.000 − 5.150.000) − 135.000 = 115.000 / CBM
        $this->assertSame(115_000.0, $breakdown['supervisor_amount']);
    }

    public function test_custom_sales_rate_applied_to_full_omset(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_FIXED_AMOUNT, '150000');
        SalesCommissionSettings::update(2.5);

        $sales = User::factory()->create([
            'sales_commission_rate_override' => 4.0,
        ]);
        $sales->assignRole('sales');
        $owner = User::factory()->create();

        $marking = Marking::query()->create([
            'user_id' => $owner->id,
            'sales_id' => $sales->id,
            'code' => 'BRKDN02',
            'customer_name' => 'Test Custom',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-BRK-2',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'created_by' => $owner->id,
        ]);

        $breakdown = SalesRevenueCalculator::earningsBreakdown($barang);
        $this->assertNotNull($breakdown);
        $this->assertSame(4.0, $breakdown['commission_rate']);
        $this->assertSame(240_000.0, $breakdown['sales_commission']);
    }
}
