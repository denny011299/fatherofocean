<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\BarangEditRequest;
use App\Models\BarangProduct;
use App\Models\User;
use App\Support\BarangEditRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class BarangEditApprovalScenariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        if (! BarangEditRequestService::isEditApprovalEnabled()) {
            $this->markTestSkipped('BYPASS_EDIT_APPROVAL aktif — matikan untuk uji pengajuan.');
        }
    }

    public function test_approve_parent_with_two_subs(): void
    {
        $barang = $this->barang();
        $parent = $this->product($barang, ['sort_order' => 0, 'ctns' => 4, 'length_cm' => 40]);
        $sub1 = $this->product($barang, [
            'sort_order' => 1,
            'parent_id' => $parent->id,
            'product_name' => 'Sub A',
            'pcs_per_box' => 3,
            'price_per_unit_rmb' => 8,
            'ctns' => 0,
            'length_cm' => 0,
            'width_cm' => 0,
            'height_cm' => 0,
        ]);

        [$approver] = $this->submitAndApprove($barang, [
            'products' => [
                ['id' => $parent->id, 'ctns' => 4, 'length_cm' => 42, 'width_cm' => 30, 'height_cm' => 20],
                ['id' => $sub1->id, 'is_sub_item' => 1, 'product_name' => 'Sub A', 'pcs_per_box' => 3, 'price_per_unit_rmb' => 8],
                ['is_sub_item' => 1, 'product_name' => 'Sub B', 'pcs_per_box' => 2, 'price_per_unit_rmb' => 5],
            ],
        ]);

        $parent->refresh();
        $this->assertSame('42.00', (string) $parent->length_cm);
        $this->assertSame(3, BarangProduct::query()->where('barang_id', $barang->id)->whereNotNull('parent_id')->count());
        $this->assertDatabaseHas('barang_products', ['barang_id' => $barang->id, 'product_name' => 'Sub B']);
    }

    public function test_approve_add_parent_without_sub_keeps_existing_sub(): void
    {
        Storage::fake('public');
        $photo = 'barang-products/sub.jpg';
        Storage::disk('public')->put($photo, 'x');

        $barang = $this->barang();
        $parent = $this->product($barang, ['sort_order' => 0, 'ctns' => 4, 'length_cm' => 40]);
        $sub = $this->product($barang, [
            'sort_order' => 1,
            'parent_id' => $parent->id,
            'product_name' => 'Sub',
            'pcs_per_box' => 4,
            'photo_path' => $photo,
            'ctns' => 0,
            'length_cm' => 0,
            'width_cm' => 0,
            'height_cm' => 0,
        ]);

        $this->submitAndApprove($barang, [
            'products' => [
                ['id' => $parent->id, 'ctns' => 4, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20],
                ['id' => $sub->id, 'is_sub_item' => 1, 'product_name' => 'Sub', 'pcs_per_box' => 4, 'price_per_unit_rmb' => 10],
                ['product_name' => 'Box 2', 'ctns' => 2, 'length_cm' => 55, 'width_cm' => 45, 'height_cm' => 35],
            ],
        ]);

        $sub->refresh();
        $this->assertSame($photo, $sub->photo_path);
        $this->assertSame(2, BarangProduct::query()->where('barang_id', $barang->id)->whereNull('parent_id')->count());
        $this->assertDatabaseHas('barang_products', ['barang_id' => $barang->id, 'product_name' => 'Box 2', 'length_cm' => 55]);
    }

    public function test_approve_mixed_lwh_new_sub_and_new_parent(): void
    {
        $barang = $this->barang();
        $parent = $this->product($barang, ['sort_order' => 0, 'ctns' => 5, 'length_cm' => 40]);

        $this->submitAndApprove($barang, [
            'products' => [
                ['id' => $parent->id, 'ctns' => 5, 'length_cm' => 43, 'width_cm' => 30, 'height_cm' => 20],
                ['is_sub_item' => 1, 'product_name' => 'Sub baru', 'pcs_per_box' => 6, 'price_per_unit_rmb' => 12],
                ['product_name' => 'Box C', 'ctns' => 1, 'length_cm' => 70, 'width_cm' => 60, 'height_cm' => 50],
            ],
        ]);

        $parent->refresh();
        $this->assertSame('43.00', (string) $parent->length_cm);
        $this->assertSame(3, BarangProduct::query()->where('barang_id', $barang->id)->count());
        $this->assertDatabaseHas('barang_products', ['barang_id' => $barang->id, 'product_name' => 'Sub baru', 'parent_id' => $parent->id]);
        $this->assertDatabaseHas('barang_products', ['barang_id' => $barang->id, 'product_name' => 'Box C', 'length_cm' => 70]);
    }

    public function test_reject_then_approve_keeps_snapshot_until_approved(): void
    {
        $barang = $this->barang();
        $product = $this->product($barang, ['length_cm' => 40]);
        $submitter = $this->user(['edit barang', 'view barang']);
        $approver = $this->user(['approve barang', 'reject barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), $this->payload($barang, [
            'products' => [['id' => $product->id, 'ctns' => 5, 'length_cm' => 99, 'width_cm' => 30, 'height_cm' => 20]],
        ]))->assertOk()->assertJson(['submitted_for_approval' => true]);

        $req = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();
        $this->actingAs($approver)->post(route('barang.edit-requests.reject', $req), ['rejection_note' => 'No'])->assertOk();

        $product->refresh();
        $this->assertSame('40.00', (string) $product->length_cm);

        $this->actingAs($submitter)->put(route('barang.update', $barang), $this->payload($barang, [
            'products' => [['id' => $product->id, 'ctns' => 5, 'length_cm' => 44, 'width_cm' => 30, 'height_cm' => 20]],
        ]))->assertOk();

        $req2 = BarangEditRequest::query()->where('barang_id', $barang->id)->where('status', 'pending')->firstOrFail();
        $this->actingAs($approver)->post(route('barang.edit-requests.approve', $req2))->assertOk();

        $product->refresh();
        $this->assertSame('44.00', (string) $product->length_cm);
    }

    /** @param  array<string, mixed>  $extra */
    private function submitAndApprove(Barang $barang, array $extra): array
    {
        $submitter = $this->user(['edit barang', 'view barang']);
        $approver = $this->user(['approve barang', 'reject barang', 'view barang']);

        $this->actingAs($submitter)
            ->put(route('barang.update', $barang), $this->payload($barang, $extra))
            ->assertOk()
            ->assertJson(['submitted_for_approval' => true]);

        $req = BarangEditRequest::query()->where('barang_id', $barang->id)->where('status', 'pending')->firstOrFail();
        $this->actingAs($approver)->post(route('barang.edit-requests.approve', $req))->assertOk();

        return [$approver];
    }

    /** @param  array<string, mixed>  $extra */
    private function payload(Barang $barang, array $extra): array
    {
        return array_merge([
            'marking' => $barang->marking,
            'customer_name' => $barang->customer_name,
            'gudang_china_id' => $barang->gudang_china_id,
        ], $extra);
    }

  /** @param  array<string, mixed>  $attrs */
    private function product(Barang $barang, array $attrs = []): BarangProduct
    {
        return BarangProduct::query()->create(array_merge([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box',
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ], $attrs));
    }

    private function barang(): Barang
    {
        return Barang::query()->create([
            'no_index' => 'IDX-' . uniqid(),
            'marking' => 'MARK-01',
            'customer_name' => 'PT Contoh',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
            'price_per_cbm' => 1000,
            'extra_charge' => 0,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function user(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            Permission::findOrCreate($p);
            $user->givePermissionTo($p);
        }

        return $user;
    }
}
