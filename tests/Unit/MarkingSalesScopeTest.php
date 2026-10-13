<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MarkingSalesScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['sales', 'marketing', 'admin_cs'] as $roleName) {
            Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    public function test_visible_to_user_limits_sales_to_own_markings(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('sales');

        $otherSales = User::factory()->create();

        Marking::query()->create([
            'code' => 'SALES-A',
            'customer_name' => 'Customer A',
            'sales_id' => $sales->id,
            'is_active' => true,
        ]);
        Marking::query()->create([
            'code' => 'SALES-B',
            'customer_name' => 'Customer B',
            'sales_id' => $otherSales->id,
            'is_active' => true,
        ]);

        $this->actingAs($sales);

        $codes = Marking::visibleToUser()->pluck('code')->all();

        $this->assertSame(['SALES-A'], $codes);
    }

    public function test_visible_to_user_limits_marketing_role_like_sales(): void
    {
        $marketing = User::factory()->create();
        $marketing->assignRole('marketing');

        $other = User::factory()->create();

        Marking::query()->create([
            'code' => 'MKT-A',
            'customer_name' => 'Customer A',
            'sales_id' => $marketing->id,
            'is_active' => true,
        ]);
        Marking::query()->create([
            'code' => 'MKT-B',
            'customer_name' => 'Customer B',
            'sales_id' => $other->id,
            'is_active' => true,
        ]);

        $this->actingAs($marketing);

        $this->assertSame(['MKT-A'], Marking::visibleToUser()->pluck('code')->all());
    }

    public function test_restrict_barangs_query_excludes_other_sales_markings(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('sales');

        Marking::query()->create([
            'code' => 'OWN/001',
            'customer_name' => 'Own',
            'sales_id' => $sales->id,
            'is_active' => true,
        ]);

        Barang::query()->create([
            'no_index' => 'IDX-OWN',
            'marking' => 'OWN/001',
            'customer_name' => 'Own',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);
        Barang::query()->create([
            'no_index' => 'IDX-OTHER',
            'marking' => 'OTHER/002',
            'customer_name' => 'Other',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $this->actingAs($sales);

        $query = Barang::query();
        Marking::restrictBarangsQuery($query);

        $this->assertSame(['IDX-OWN'], $query->pluck('no_index')->all());
    }

    public function test_barang_visible_to_user_returns_false_for_out_of_scope_barang(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('sales');

        $barang = Barang::query()->create([
            'no_index' => 'IDX-OTHER',
            'marking' => 'OTHER/999',
            'customer_name' => 'Other',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ]);

        $this->actingAs($sales);

        $this->assertFalse(Marking::barangVisibleToUser($barang));
    }
}
