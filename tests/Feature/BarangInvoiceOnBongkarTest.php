<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Container;
use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterPricingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class BarangInvoiceOnBongkarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_after_insert_creates_invoice_when_billable(): void
    {
        $barang = $this->makeBillableBarang('IDX-MASUK-INV');

        $barang->afterInsert(null);
        $this->app->terminate();

        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $barang->user_id,
            'status' => 'draft',
        ]);
        $invoice = \App\Models\Invoice::query()
            ->whereHas('items', static fn ($q) => $q->where('barang_id', $barang->id))
            ->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->due_date);
    }

    public function test_due_date_starts_when_status_becomes_bongkar(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-20');

        $user = $this->userWithPermission('update tracking status');
        $barang = $this->makeBillableBarang('IDX-DUE-BONGKAR');
        $barang->recordInvoiceOnMasuk();

        $invoice = \App\Models\Invoice::query()
            ->whereHas('items', static fn ($q) => $q->where('barang_id', $barang->id))
            ->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->due_date);

        $barang->update(['status' => Barang::STATUS_ETA]);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_BONGKAR,
            ])
            ->assertOk();

        $this->app->terminate();

        $invoice->refresh();
        $this->assertSame('2026-07-20', $invoice->due_date?->toDateString());

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_masuk_invoice_shows_partai_minus_until_container_load(): void
    {
        $barang = $this->makeBillableBarang('IDX-PARTAI-MINUS');
        $barang->recordInvoiceOnMasuk();

        $invoice = \App\Models\Invoice::query()
            ->whereHas('items', static fn ($q) => $q->where('barang_id', $barang->id))
            ->first();

        $this->assertNotNull($invoice);
        $this->assertSame('-', $invoice->resolvedPartaiLabel());
    }

    public function test_masuk_same_marking_without_container_consolidates_into_one_invoice(): void
    {
        $customer = User::factory()->create();
        $marking = 'MARK-MASUK-COMBINE';

        $barangA = Barang::query()->create([
            'no_index' => 'IDX-MASUK-A',
            'marking' => $marking,
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now()->toDateString(),
            'destination' => 'Surabaya',
            'ctns' => 1,
            'total_cbm' => 1,
            'user_id' => $customer->id,
            'price_per_cbm' => 150,
            'insurance_value' => 0,
            'insurance_rate' => 0,
        ]);

        $barangB = Barang::query()->create([
            'no_index' => 'IDX-MASUK-B',
            'marking' => $marking,
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now()->toDateString(),
            'destination' => 'Surabaya',
            'ctns' => 2,
            'total_cbm' => 2,
            'user_id' => $customer->id,
            'price_per_cbm' => 150,
            'insurance_value' => 0,
            'insurance_rate' => 0,
        ]);

        $barangA->recordInvoiceOnMasuk();
        $barangB->recordInvoiceOnMasuk();

        $invoiceIds = DB::table('invoice_items')
            ->whereIn('barang_id', [$barangA->id, $barangB->id])
            ->pluck('invoice_id')
            ->unique()
            ->values();

        $this->assertCount(1, $invoiceIds);
    }

    public function test_gudang_indo_can_update_container_tracking_to_bongkar(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_gudang_indo', ['view tracking', 'update tracking status', 'view container']);
        $container = Container::query()->create([
            'partai' => 'PARTAI-GI',
            'container_no' => 'CONT-GI',
            'type' => '40ft',
            'status' => Container::STATUS_UNLOADING,
        ]);

        $barang = $this->makeBillableBarang('IDX-GI');
        $barang->update(['status' => Barang::STATUS_ETA]);
        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('tracking.staff.update', $container), [
                'status' => Container::TRACKING_BONGKAR,
            ])
            ->assertOk();

        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
    }

    public function test_container_tracking_bongkar_creates_invoice(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status', 'view container']);
        $container = Container::query()->create([
            'partai' => 'PARTAI-INV',
            'container_no' => 'CONT-INV',
            'type' => '40ft',
            'status' => Container::STATUS_UNLOADING,
        ]);

        $barang = $this->makeBillableBarang('IDX-BONGKAR');
        $barang->update(['status' => Barang::STATUS_ETA]);
        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('tracking.staff.update', $container), [
                'status' => Container::TRACKING_BONGKAR,
            ])
            ->assertOk();

        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $barang->user_id,
            'status' => 'draft',
        ]);
    }

    public function test_eloquent_status_bongkar_creates_invoice_without_http(): void
    {
        $barang = $this->makeBillableBarang('IDX-OBSERVER');
        $barang->update(['status' => Barang::STATUS_ETA]);
        $barang->update(['status' => Barang::STATUS_BONGKAR]);

        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
    }

    public function test_manual_barang_status_bongkar_creates_invoice(): void
    {
        $user = $this->userWithPermission('update tracking status');
        $barang = $this->makeBillableBarang('IDX-MANUAL');
        $barang->update(['status' => Barang::STATUS_ETA]);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_BONGKAR,
            ])
            ->assertOk();

        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
    }

    public function test_container_bongkar_resolves_marking_price_when_barang_price_zero(): void
    {
        $customer = User::factory()->create();
        $markingCode = 'MARK-RESOLVE';
        $marking = Marking::query()->create([
            'code' => $markingCode,
            'customer_name' => 'Resolve Customer',
            'user_id' => $customer->id,
        ]);
        $pricingItem = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 175,
            'gw_price' => 800,
            'is_active' => true,
        ]);
        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $pricingItem->id,
            'volume_price' => 175,
            'gw_price' => 800,
        ]);

        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status', 'view container']);
        $container = Container::query()->create([
            'partai' => 'PARTAI-PRICE',
            'container_no' => 'CONT-PRICE',
            'type' => '40ft',
            'status' => Container::STATUS_ARRIVED,
        ]);

        $barang = Barang::query()->create([
            'no_index' => 'IDX-RESOLVE',
            'marking' => $markingCode,
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_ETA,
            'tanggal_masuk' => now()->toDateString(),
            'destination' => 'Surabaya',
            'ctns' => 1,
            'total_cbm' => 1,
            'user_id' => $customer->id,
            'price_per_cbm' => 0,
            'insurance_value' => 0,
            'insurance_rate' => 0,
        ]);
        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('tracking.staff.update', $container), [
                'status' => Container::TRACKING_BONGKAR,
            ])
            ->assertOk();

        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
        $this->assertSame(175.0, (float) $barang->fresh()->price_per_cbm);
    }

    public function test_bongkar_status_is_normalized_case_insensitively(): void
    {
        $user = $this->userWithPermission('update tracking status');
        $barang = $this->makeBillableBarang('IDX-CASE');
        $barang->update(['status' => Barang::STATUS_ETA]);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => 'Bongkar',
            ])
            ->assertOk();

        $this->assertSame(Barang::STATUS_BONGKAR, $barang->fresh()->status);
        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
    }

    public function test_existing_invoice_item_is_not_duplicated_on_bongkar(): void
    {
        $user = $this->userWithPermission('update tracking status');
        $barang = $this->makeBillableBarang('IDX-EXISTING');
        $barang->update(['status' => Barang::STATUS_ETA]);

        $now = now();
        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => 'INV-TEST',
            'user_id' => $barang->user_id,
            'price_per_cbm' => 100,
            'status' => 'draft',
            'due_date' => now()->addDays(7)->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'barang_id' => $barang->id,
            'cbm' => 1,
            'price_per_cbm' => 100,
            'insurance' => 0,
            'extra' => 0,
            'line_total' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_BONGKAR,
            ])
            ->assertOk();

        $this->app->terminate();

        $this->assertSame(
            1,
            DB::table('invoice_items')->where('barang_id', $barang->id)->count(),
        );
    }

    public function test_container_bongkar_combines_same_marking_barangs_into_one_invoice(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status', 'view container']);
        $customer = User::factory()->create();
        $marking = 'MARK-COMBINE';

        $container = Container::query()->create([
            'partai' => 'PARTAI-COMBINE',
            'container_no' => 'CONT-COMBINE',
            'type' => '40ft',
            'status' => Container::STATUS_UNLOADING,
        ]);

        $barangA = Barang::query()->create([
            'no_index' => 'IDX-COMBINE-A',
            'marking' => $marking,
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_ETA,
            'tanggal_masuk' => now()->toDateString(),
            'destination' => 'Surabaya',
            'ctns' => 1,
            'total_cbm' => 1,
            'user_id' => $customer->id,
            'price_per_cbm' => 150,
            'insurance_value' => 0,
            'insurance_rate' => 0,
        ]);

        $barangB = Barang::query()->create([
            'no_index' => 'IDX-COMBINE-B',
            'marking' => $marking,
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_ETA,
            'tanggal_masuk' => now()->toDateString(),
            'destination' => 'Surabaya',
            'ctns' => 2,
            'total_cbm' => 2,
            'user_id' => $customer->id,
            'price_per_cbm' => 150,
            'insurance_value' => 0,
            'insurance_rate' => 0,
        ]);

        foreach ([$barangA, $barangB] as $barang) {
            $container->barangs()->attach($barang->id, [
                'loaded_at' => now(),
                'loaded_by' => null,
            ]);
        }

        $this->actingAs($user)
            ->postJson(route('tracking.staff.update', $container), [
                'status' => Container::TRACKING_BONGKAR,
            ])
            ->assertOk();

        $invoiceIds = DB::table('invoice_items')
            ->whereIn('barang_id', [$barangA->id, $barangB->id])
            ->pluck('invoice_id')
            ->unique()
            ->values();

        $this->assertCount(1, $invoiceIds);

        $invoiceId = (int) $invoiceIds->first();
        $this->assertSame(2, DB::table('invoice_items')->where('invoice_id', $invoiceId)->count());
    }

    public function test_container_bongkar_keeps_separate_invoices_for_different_containers(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status', 'view container']);
        $customer = User::factory()->create();
        $marking = 'MARK-SPLIT';

        $containerA = Container::query()->create([
            'partai' => 'PARTAI-A',
            'container_no' => 'CONT-A',
            'type' => '40ft',
            'status' => Container::STATUS_UNLOADING,
        ]);
        $containerB = Container::query()->create([
            'partai' => 'PARTAI-B',
            'container_no' => 'CONT-B',
            'type' => '40ft',
            'status' => Container::STATUS_UNLOADING,
        ]);

        $barangA = $this->makeBillableBarang('IDX-SPLIT-A');
        $barangA->update(['marking' => $marking, 'user_id' => $customer->id]);
        $barangB = $this->makeBillableBarang('IDX-SPLIT-B');
        $barangB->update(['marking' => $marking, 'user_id' => $customer->id]);

        $containerA->barangs()->attach($barangA->id, ['loaded_at' => now(), 'loaded_by' => null]);
        $containerB->barangs()->attach($barangB->id, ['loaded_at' => now(), 'loaded_by' => null]);
        $barangA->update(['status' => Barang::STATUS_ETA]);
        $barangB->update(['status' => Barang::STATUS_ETA]);

        $this->actingAs($user)
            ->postJson(route('tracking.staff.update', $containerA), ['status' => Container::TRACKING_BONGKAR])
            ->assertOk();
        $this->actingAs($user)
            ->postJson(route('tracking.staff.update', $containerB), ['status' => Container::TRACKING_BONGKAR])
            ->assertOk();

        $invoiceIds = DB::table('invoice_items')
            ->whereIn('barang_id', [$barangA->id, $barangB->id])
            ->pluck('invoice_id')
            ->unique()
            ->values();

        $this->assertCount(2, $invoiceIds);
    }

    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate($permission);
        $user->givePermissionTo($permission);

        return $user;
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** @param list<string> $permissions */
    private function userWithRoleAndPermissions(string $role, array $permissions): User
    {
        Role::findOrCreate($role);
        $user = $this->userWithPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function makeBillableBarang(string $noIndex): Barang
    {
        $customer = User::factory()->create();

        return Barang::query()->create([
            'no_index' => $noIndex,
            'marking' => 'MARK-'.$noIndex,
            'warehouse_origin' => 'guangzhou',
            'status' => Barang::STATUS_STUFFING,
            'tanggal_masuk' => now()->toDateString(),
            'destination' => 'Surabaya',
            'ctns' => 1,
            'total_cbm' => 1,
            'user_id' => $customer->id,
            'price_per_cbm' => 150,
            'insurance_value' => 0,
            'insurance_rate' => 0,
        ]);
    }
}
