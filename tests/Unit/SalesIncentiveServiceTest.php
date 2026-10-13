<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\CompanyRevenueItemModal;
use App\Models\Marking;
use App\Models\MasterPricingItem;
use App\Models\SalesIncentiveLine;
use App\Models\SalesIncentivePeriod;
use App\Models\SalesIncentiveTier;
use App\Models\User;
use App\Support\SalesIncentiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SalesIncentiveServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
    }

    public function test_sync_updates_paid_cbm_after_status_leaves_stuffing(): void
    {
        [$sales, $item, $barang] = $this->seedSalesBarangWithTier(25.0);

        SalesIncentiveService::syncForBarang($barang);

        $line = SalesIncentiveLine::query()->where('barang_id', $barang->id)->first();
        $this->assertNotNull($line);
        $this->assertSame(0.0, (float) $line->paid_cbm);

        $barang->update(['status' => Barang::STATUS_ETD]);
        $this->attachPaidInvoice($barang);

        SalesIncentiveService::syncForBarang($barang->fresh());

        $line = $line->fresh();
        $this->assertSame(25.0, (float) $line->paid_cbm);

        $period = SalesIncentivePeriod::query()
            ->where('sales_id', $sales->id)
            ->where('period', $line->period)
            ->first();

        $this->assertNotNull($period);
        $this->assertSame(500_000.0, (float) $period->estimasi_amount);
        $this->assertSame(500_000.0, (float) $period->cair_amount);
    }

    public function test_heal_backfills_null_pricing_item_id_and_recalcs(): void
    {
        [$sales, $item, $barang] = $this->seedSalesBarangWithTier(40.0);
        SalesIncentiveService::syncForBarang($barang);

        SalesIncentiveLine::query()->where('barang_id', $barang->id)->update([
            'pricing_item_id' => null,
            'paid_cbm' => 0,
        ]);
        SalesIncentivePeriod::query()->where('sales_id', $sales->id)->update([
            'estimasi_amount' => 0,
            'cair_amount' => 0,
        ]);

        $this->attachPaidInvoice($barang);
        $barang->update(['status' => Barang::STATUS_BONGKAR]);

        $result = SalesIncentiveService::healAndRecalc();

        $this->assertGreaterThan(0, $result['pricing_backfilled']);
        $this->assertGreaterThan(0, $result['periods_recalculated']);

        $line = SalesIncentiveLine::query()->where('barang_id', $barang->id)->first();
        $this->assertSame((int) $item->id, (int) $line->pricing_item_id);
        $this->assertSame(40.0, (float) $line->paid_cbm);

        $period = SalesIncentivePeriod::query()
            ->where('sales_id', $sales->id)
            ->where('period', $line->period)
            ->first();

        $this->assertSame(1_000_000.0, (float) $period->estimasi_amount);
        $this->assertSame(1_000_000.0, (float) $period->cair_amount);
    }

    public function test_sync_creates_line_from_stuffing_history_when_current_status_advanced(): void
    {
        [$sales, $item, $barang] = $this->seedSalesBarangWithTier(20.0);

        DB::table('tracking_statuses')->insert([
            'barang_id' => $barang->id,
            'status' => Barang::STATUS_STUFFING,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $barang->update(['status' => Barang::STATUS_ETA]);

        SalesIncentiveService::syncForBarang($barang->fresh());

        $line = SalesIncentiveLine::query()->where('barang_id', $barang->id)->first();
        $this->assertNotNull($line);
        $this->assertSame((int) $sales->id, (int) $line->sales_id);
        $this->assertSame((int) $item->id, (int) $line->pricing_item_id);
    }

    /**
     * @return array{0: User, 1: MasterPricingItem, 2: Barang}
     */
    private function seedSalesBarangWithTier(float $cbm): array
    {
        $sales = User::factory()->create();
        $sales->assignRole('sales');

        $owner = User::factory()->create();
        $item = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 0,
            'gw_price' => 0,
            'is_active' => true,
        ]);
        CompanyRevenueItemModal::query()->create([
            'pricing_item_id' => $item->id,
            'cost_volume_price' => 575_000,
            'cost_gw_price' => 5_500,
        ]);

        SalesIncentiveTier::query()->create([
            'name' => 'Per 20 CBM',
            'pricing_item_id' => $item->id,
            'cbm_per_block' => 20,
            'amount_per_block' => 500_000,
            'sort_order' => 1,
            'is_active' => true,
        ])->syncPricingItemIds([$item->id]);

        $marking = Marking::query()->create([
            'user_id' => $owner->id,
            'sales_id' => $sales->id,
            'code' => 'INC'.uniqid(),
            'customer_name' => 'Test',
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-INC-'.uniqid(),
            'marking' => $marking->code,
            'cbm_type' => (string) $item->id,
            'price_per_cbm' => 1_000_000,
            'total_cbm' => $cbm,
            'total_gross_weight' => 0,
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => '2026-03-15',
            'created_by' => $owner->id,
        ]);

        return [$sales, $item, $barang];
    }

    private function attachPaidInvoice(Barang $barang): void
    {
        $now = now();
        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => 'INV-'.$barang->no_index,
            'user_id' => $barang->created_by,
            'price_per_cbm' => 1_000_000,
            'status' => 'paid',
            'due_date' => now()->addDays(7)->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'barang_id' => $barang->id,
            'cbm' => (float) $barang->total_cbm,
            'price_per_cbm' => 1_000_000,
            'insurance' => 0,
            'extra' => 0,
            'line_total' => 1_000_000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
