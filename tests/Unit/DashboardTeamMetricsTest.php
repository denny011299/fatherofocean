<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\User;
use App\Support\DashboardTeamMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DashboardTeamMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
    }

    public function test_total_cbm_uses_tanggal_masuk_without_requiring_paid_invoice(): void
    {
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $subordinate = User::factory()->create();
        $subordinate->assignRole('sales');
        $supervisor->subordinates()->attach($subordinate->id);

        $marking = Marking::query()->create([
            'user_id' => $supervisor->id,
            'sales_id' => $subordinate->id,
            'code' => 'TEAM01',
            'customer_name' => 'Team Customer',
        ]);

        Marking::query()->create([
            'user_id' => User::factory()->create()->id,
            'sales_id' => User::factory()->create()->id,
            'code' => 'OTHER01',
            'customer_name' => 'Other',
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-TEAM',
            'marking' => $marking->code,
            'total_cbm' => 2.456,
            'tanggal_masuk' => '2026-05-15',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-OTHER',
            'marking' => 'OTHER01',
            'total_cbm' => 9.999,
            'tanggal_masuk' => '2026-05-20',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $cbm = DashboardTeamMetrics::totalCbm($supervisor, '2026-05-01', '2026-05-31');

        $this->assertSame(2.456, $cbm);
    }

    public function test_paid_omset_uses_tanggal_masuk_and_billable_base_without_paid_invoice(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('sales');

        $marking = Marking::query()->create([
            'user_id' => $sales->id,
            'sales_id' => $sales->id,
            'code' => 'SALES01',
            'customer_name' => 'Sales Customer',
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-SALES',
            'marking' => $marking->code,
            'price_per_cbm' => 5_000_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'tanggal_masuk' => '2026-05-10',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $omset = DashboardTeamMetrics::paidOmset($sales, '2026-05-01', '2026-05-31');

        $this->assertSame(5_000_000.0, $omset);
    }

    public function test_commission_total_uses_sales_rate_on_billable_base(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('sales');

        $marking = Marking::query()->create([
            'user_id' => $sales->id,
            'sales_id' => $sales->id,
            'code' => 'SALES02',
            'customer_name' => 'Sales Customer',
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-SALES2',
            'marking' => $marking->code,
            'price_per_cbm' => 5_000_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'tanggal_masuk' => '2026-05-10',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $commission = DashboardTeamMetrics::commissionTotal($sales, '2026-05-01', '2026-05-31');

        $this->assertSame(125_000.0, $commission);
    }
}
