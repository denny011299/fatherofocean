<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\User;
use App\Support\CompanyRevenueSettings;
use App\Support\SalesCommissionSettings;
use App\Support\SalesRevenueCalculator;
use App\Support\SalesSalaryAggregator;
use App\Support\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SupervisorMarketingCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
    }

    public function test_supervisor_assigned_as_marketing_earns_capital_gain_only(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_FIXED_AMOUNT, '150000');
        SalesCommissionSettings::update(2.5);

        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $customer = User::factory()->create();

        $marking = Marking::query()->create([
            'user_id' => $customer->id,
            'sales_id' => $supervisor->id,
            'code' => 'SUPMKT01',
            'customer_name' => 'Customer',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-SUPMKT-1',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'cost_price_per_cbm' => 5_750_000,
            'company_loyalty_amount' => 150_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'created_by' => $customer->id,
        ]);

        $breakdown = SalesRevenueCalculator::earningsBreakdown($barang);
        $this->assertNotNull($breakdown);
        $this->assertSame($supervisor->id, $breakdown['sales_id']);
        $this->assertSame($supervisor->id, $breakdown['supervisor_id']);
        $this->assertSame(0.0, $breakdown['sales_commission']);
        $this->assertSame(250_000.0, $breakdown['markup']);
        $this->assertSame(100_000.0, $breakdown['supervisor_amount']);

        $totals = SalesRevenueCalculator::totalsForUser($supervisor);
        $this->assertSame(100_000.0, $totals['estimasi']);
        $this->assertSame(0.0, $totals['fix']);
    }

    public function test_sales_marketing_with_supervisor_owner_keeps_split(): void
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
            'code' => 'SPLIT01',
            'customer_name' => 'Test',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-SPLIT-1',
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
        $this->assertSame($sales->id, $breakdown['sales_id']);
        $this->assertSame($owner->id, $breakdown['supervisor_id']);
        $this->assertSame(150_000.0, $breakdown['sales_commission']);
        $this->assertSame(100_000.0, $breakdown['supervisor_amount']);
    }

    public function test_supervisor_resolved_from_sales_subordinate_hierarchy(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_FIXED_AMOUNT, '150000');
        SalesCommissionSettings::update(2.5);

        $pic = User::factory()->create(['name' => 'Daniel PIC']);
        $pic->assignRole('supervisor');

        $sales = User::factory()->create(['name' => 'Barnett Sales']);
        $sales->assignRole('sales');
        $pic->subordinates()->attach($sales->id);

        $customer = User::factory()->create(['name' => 'Customer Portal']);

        // Marking owner is customer (not supervisor) — PIC must come from sales hierarchy.
        $marking = Marking::query()->create([
            'user_id' => $customer->id,
            'sales_id' => $sales->id,
            'code' => 'HIER01',
            'customer_name' => 'Customer',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-HIER-1',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'cost_price_per_cbm' => 5_750_000,
            'company_loyalty_amount' => 150_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'created_by' => $customer->id,
        ]);

        $this->assertSame(
            $pic->id,
            SalesRevenueCalculator::resolveSupervisorId($barang, $sales->id),
        );

        $breakdown = SalesRevenueCalculator::earningsBreakdown($barang);
        $this->assertNotNull($breakdown);
        $this->assertSame($sales->id, $breakdown['sales_id']);
        $this->assertSame($pic->id, $breakdown['supervisor_id']);
        $this->assertSame(150_000.0, $breakdown['sales_commission']);
        $this->assertSame(100_000.0, $breakdown['supervisor_amount']);
    }

    public function test_hierarchy_pic_wins_even_when_sales_also_has_supervisor_role(): void
    {
        $pic = User::factory()->create(['name' => 'Daniel PIC']);
        $pic->assignRole('supervisor');

        // Sales yang kebetulan juga punya role supervisor — tetap ikut atasan di master user.
        $sales = User::factory()->create(['name' => 'Alwin Dual']);
        $sales->assignRole(['sales', 'supervisor']);
        $pic->subordinates()->attach($sales->id);

        $customer = User::factory()->create();
        $marking = Marking::query()->create([
            'user_id' => $customer->id,
            'sales_id' => $sales->id,
            'code' => 'HIER02',
            'customer_name' => 'Customer',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-HIER-2',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'cost_price_per_cbm' => 5_750_000,
            'total_cbm' => 1.0,
            'status' => 'stuffing',
            'created_by' => $customer->id,
        ]);

        $this->assertSame(
            $pic->id,
            SalesRevenueCalculator::resolveSupervisorId($barang, $sales->id),
        );
        $this->assertSame(
            $pic->id,
            SalesRevenueCalculator::resolvePicIdFromSalesHierarchy($sales->id),
        );
    }

    /** Rodrico = sales biasa: tanpa atasan di master user → jangan masuk KOMISI PIC. */
    public function test_pure_sales_without_atasan_is_not_listed_as_pic(): void
    {
        $rodrico = User::factory()->create(['name' => 'Rodrico']);
        $rodrico->assignRole('sales');

        $customer = User::factory()->create();
        $marking = Marking::query()->create([
            'user_id' => $customer->id,
            'sales_id' => $rodrico->id,
            'code' => 'RODR01',
            'customer_name' => 'Customer',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-RODR-1',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'cost_price_per_cbm' => 5_750_000,
            'total_cbm' => 1.0,
            'status' => 'stuffing',
            'created_by' => $customer->id,
        ]);

        $this->assertNull(
            SalesRevenueCalculator::resolveSupervisorId($barang, $rodrico->id),
        );
        $this->assertFalse(SalesRevenueCalculator::isPicParentUserId($rodrico->id));
    }

    /** Rodrico = sales, PIC = atasannya dari master user (anak buah), bukan Rodrico. */
    public function test_pure_sales_pic_is_atasan_from_master_user(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_FIXED_AMOUNT, '150000');
        SalesCommissionSettings::update(2.5);

        $atasan = User::factory()->create(['name' => 'PIC Atasan']);
        $atasan->assignRole('supervisor');

        $rodrico = User::factory()->create(['name' => 'Rodrico']);
        $rodrico->assignRole('sales');
        $atasan->subordinates()->attach($rodrico->id);

        $customer = User::factory()->create();
        $marking = Marking::query()->create([
            'user_id' => $customer->id,
            'sales_id' => $rodrico->id,
            'code' => 'RODR02',
            'customer_name' => 'Customer',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-RODR-2',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'cost_price_per_cbm' => 5_750_000,
            'company_loyalty_amount' => 150_000,
            'total_cbm' => 1.0,
            'status' => 'stuffing',
            'created_by' => $customer->id,
        ]);

        $this->assertSame(
            $atasan->id,
            SalesRevenueCalculator::resolveSupervisorId($barang, $rodrico->id),
        );

        $breakdown = SalesRevenueCalculator::earningsBreakdown($barang);
        $this->assertNotNull($breakdown);
        $this->assertSame($rodrico->id, $breakdown['sales_id']);
        $this->assertSame($atasan->id, $breakdown['supervisor_id']);
        $this->assertNotSame($rodrico->id, $breakdown['supervisor_id']);
    }

    public function test_supervisor_marketing_scoped_in_commission_rows(): void
    {
        SalesCommissionSettings::update(2.5);

        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $customer = User::factory()->create();

        $marking = Marking::query()->create([
            'user_id' => $customer->id,
            'sales_id' => $supervisor->id,
            'code' => 'SCOPE01',
            'customer_name' => 'Customer',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-SCOPE-1',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'cost_price_per_cbm' => 5_750_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'created_by' => $customer->id,
        ]);

        $rows = SalesRevenueCalculator::commissionRows($supervisor);
        $this->assertCount(1, $rows);
        $this->assertSame($supervisor->id, $rows[0]['supervisor_id']);
        $this->assertSame(0.0, $rows[0]['estimated_amount']);
        $this->assertGreaterThan(0.0, $rows[0]['supervisor_amount']);
    }

    public function test_marketing_staff_scope_includes_supervisor(): void
    {
        $supervisor = User::factory()->create(['name' => 'Supervisor User']);
        $supervisor->assignRole('supervisor');

        $sales = User::factory()->create(['name' => 'Sales User']);
        $sales->assignRole('sales');

        $ids = User::query()->marketingStaff()->orderBy('name')->pluck('id')->all();

        $this->assertContains($supervisor->id, $ids);
        $this->assertContains($sales->id, $ids);
    }

    public function test_supervisor_salary_detail_uses_capital_gain_amount(): void
    {
        SystemSetting::setValue(CompanyRevenueSettings::KEY_FIXED_AMOUNT, '150000');
        SalesCommissionSettings::update(2.5);

        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $customer = User::factory()->create();

        $marking = Marking::query()->create([
            'user_id' => $customer->id,
            'sales_id' => $supervisor->id,
            'code' => 'PAY01',
            'customer_name' => 'Customer',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-PAY-1',
            'marking' => $marking->code,
            'price_per_cbm' => 6_000_000,
            'cost_price_per_cbm' => 5_750_000,
            'company_loyalty_amount' => 150_000,
            'total_cbm' => 1.0,
            'total_gross_weight' => 0,
            'status' => 'stuffing',
            'tanggal_masuk' => '2026-06-15',
            'created_by' => $customer->id,
        ]);

        $this->attachPaidInvoice($barang);
        \App\Support\ShipmentEarningsSettlement::syncForBarang($barang);

        $detail = SalesSalaryAggregator::periodDetail((int) $supervisor->id, '2026-06');
        $this->assertCount(1, $detail['items']);
        $this->assertSame(100_000.0, $detail['total_amount']);
    }

    private function attachPaidInvoice(Barang $barang): void
    {
        $now = now();
        $invoiceId = (int) \Illuminate\Support\Facades\DB::table('invoices')->insertGetId([
            'invoice_no' => 'INV-'.$barang->no_index,
            'user_id' => $barang->created_by,
            'price_per_cbm' => 6_000_000,
            'status' => 'paid',
            'due_date' => now()->addDays(7)->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        \Illuminate\Support\Facades\DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'barang_id' => $barang->id,
            'cbm' => 1,
            'price_per_cbm' => 6_000_000,
            'insurance' => 0,
            'extra' => 0,
            'line_total' => 6_000_000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
