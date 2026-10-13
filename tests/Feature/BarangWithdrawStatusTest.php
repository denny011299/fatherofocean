<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Container;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class BarangWithdrawStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_admin_cs_can_withdraw_stuffing_barang(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status']);
        $barang = $this->makeBarang('IDX-WD-1', Barang::STATUS_STUFFING);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_DITARIK_KEMBALI,
            ])
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $this->assertSame(Barang::STATUS_DITARIK_KEMBALI, $barang->fresh()->status);
    }

    public function test_withdraw_removes_barang_from_draft_invoice(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status']);
        $barang = $this->makeBillableBarang('IDX-WD-INV-1', Barang::STATUS_STUFFING);
        $barang->recordInvoiceOnMasuk();

        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_DITARIK_KEMBALI,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('invoice_items', ['barang_id' => $barang->id]);
    }

    public function test_withdraw_deletes_empty_draft_invoice(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status']);
        $barang = $this->makeBillableBarang('IDX-WD-INV-2', Barang::STATUS_STUFFING);
        $barang->recordInvoiceOnMasuk();

        $invoiceId = (int) DB::table('invoice_items')->where('barang_id', $barang->id)->value('invoice_id');
        $this->assertGreaterThan(0, $invoiceId);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_DITARIK_KEMBALI,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => $invoiceId]);
    }

    public function test_reactivate_withdrawn_barang_recreates_draft_invoice(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status']);
        $barang = $this->makeBillableBarang('IDX-WD-INV-3', Barang::STATUS_STUFFING);
        $barang->recordInvoiceOnMasuk();

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_DITARIK_KEMBALI,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('invoice_items', ['barang_id' => $barang->id]);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang->fresh()), [
                'status' => Barang::STATUS_STUFFING,
            ])
            ->assertOk();

        $this->assertDatabaseHas('invoice_items', ['barang_id' => $barang->id]);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $barang->user_id,
            'status' => 'draft',
        ]);
    }

    public function test_withdraw_does_not_remove_sent_invoice_line(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status']);
        $barang = $this->makeBillableBarang('IDX-WD-INV-4', Barang::STATUS_STUFFING);
        $now = now();

        $invoiceId = (int) DB::table('invoices')->insertGetId([
            'invoice_no' => 'INV-SENT-WD',
            'user_id' => $barang->user_id,
            'price_per_cbm' => 150,
            'status' => 'sent',
            'due_date' => now()->addDays(7)->toDateString(),
            'grand_total' => 150,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'barang_id' => $barang->id,
            'cbm' => 1,
            'price_per_cbm' => 150,
            'insurance' => 0,
            'extra' => 0,
            'line_total' => 150,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_DITARIK_KEMBALI,
            ])
            ->assertOk();

        $this->assertDatabaseHas('invoice_items', [
            'barang_id' => $barang->id,
            'invoice_id' => $invoiceId,
        ]);
    }

    public function test_gudang_indo_cannot_withdraw_barang(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_gudang_indo', ['view tracking', 'update tracking status']);
        $barang = $this->makeBarang('IDX-WD-2', Barang::STATUS_STUFFING);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_DITARIK_KEMBALI,
            ])
            ->assertForbidden();
    }

    public function test_cannot_withdraw_barang_already_in_container(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status']);
        $barang = $this->makeBarang('IDX-WD-3', Barang::STATUS_STUFFING);
        $container = Container::query()->create([
            'partai' => 'PARTAI-WD',
            'container_no' => 'CONT-WD',
            'type' => '40ft',
            'status' => Container::STATUS_OPEN,
        ]);
        $container->barangs()->attach($barang->id, [
            'loaded_at' => now(),
            'loaded_by' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_DITARIK_KEMBALI,
            ])
            ->assertStatus(422);
    }

    public function test_admin_cs_can_reactivate_withdrawn_barang_to_stuffing(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view tracking', 'update tracking status']);
        $barang = $this->makeBarang('IDX-WD-4', Barang::STATUS_DITARIK_KEMBALI);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_STUFFING,
            ])
            ->assertOk();

        $this->assertSame(Barang::STATUS_STUFFING, $barang->fresh()->status);
    }

    public function test_admin_cs_can_reactivate_without_tracking_permission(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_cs', ['view barang']);
        $barang = $this->makeBarang('IDX-WD-6', Barang::STATUS_DITARIK_KEMBALI);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_STUFFING,
            ])
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $this->assertSame(Barang::STATUS_STUFFING, $barang->fresh()->status);
    }

    public function test_gudang_cannot_change_withdrawn_barang_to_etd(): void
    {
        $user = $this->userWithRoleAndPermissions('admin_gudang_indo', ['view tracking', 'update tracking status']);
        $barang = $this->makeBarang('IDX-WD-5', Barang::STATUS_DITARIK_KEMBALI);

        $this->actingAs($user)
            ->postJson(route('tracking.update', $barang), [
                'status' => Barang::STATUS_ETD,
            ])
            ->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function userWithRoleAndPermissions(string $role, array $permissions): User
    {
        Role::findOrCreate($role);
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }

        $user->assignRole($role);

        return $user;
    }

    private function makeBarang(string $noIndex, string $status): Barang
    {
        return Barang::query()->create([
            'no_index' => $noIndex,
            'marking' => 'MARK-' . $noIndex,
            'warehouse_origin' => 'guangzhou',
            'status' => $status,
            'tanggal_masuk' => now()->toDateString(),
            'destination' => 'Surabaya',
            'ctns' => 1,
            'total_cbm' => 1,
            'insurance_value' => 0,
            'insurance_rate' => 0,
        ]);
    }

    private function makeBillableBarang(string $noIndex, string $status): Barang
    {
        $customer = User::factory()->create();

        return Barang::query()->create([
            'no_index' => $noIndex,
            'marking' => 'MARK-' . $noIndex,
            'warehouse_origin' => 'guangzhou',
            'status' => $status,
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
