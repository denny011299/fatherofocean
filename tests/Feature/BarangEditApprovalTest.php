<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\BarangEditRequest;
use App\Models\BarangProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class BarangEditApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    private function makeBarang(array $overrides = []): Barang
    {
        return Barang::query()->create(array_merge([
            'no_index' => 'IDX-' . uniqid(),
            'marking' => 'MARK-01',
            'customer_name' => 'PT Contoh',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
            'price_per_cbm' => 1000,
            'extra_charge' => 0,
            'notes' => 'Catatan awal',
        ], $overrides));
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function baseUpdatePayload(Barang $barang): array
    {
        return [
            'marking' => $barang->marking,
            'customer_name' => $barang->customer_name,
            'gudang_china_id' => $barang->gudang_china_id,
        ];
    }

    public function test_notes_only_change_saves_directly_without_pengajuan(): void
    {
        $barang = $this->makeBarang(['notes' => 'Catatan awal']);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);

        $response = $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            ['notes' => 'Catatan baru'],
        ));

        $response->assertOk()->assertJson([
            'success' => true,
            'saved_directly' => true,
        ]);
        $response->assertJsonMissing(['submitted_for_approval' => true]);

        $barang->refresh();
        $this->assertSame('Catatan baru', $barang->notes);
        $this->assertDatabaseMissing('barang_edit_requests', [
            'barang_id' => $barang->id,
        ]);
    }

    public function test_lwh_change_creates_pending_request_without_updating_barang(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'pcs_per_box' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);

        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);

        $response = $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'pcs_per_box' => 10,
                    'length_cm' => 41,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ));

        $response->assertOk()->assertJson([
            'success' => true,
            'submitted_for_approval' => true,
        ]);
        $response->assertJsonMissing(['saved_directly' => true]);

        $product->refresh();
        $this->assertSame('40.00', (string) $product->length_cm);

        $this->assertDatabaseHas('barang_edit_requests', [
            'barang_id' => $barang->id,
            'submitted_by' => $submitter->id,
            'status' => BarangEditRequest::STATUS_PENDING,
        ]);
    }

    public function test_pcs_and_rmb_only_change_saves_directly(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'pcs_per_box' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'price_per_unit_rmb' => 18.5,
            'total_price_rmb' => 925,
        ]);

        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);

        $response = $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'pcs_per_box' => 12,
                    'length_cm' => 40,
                    'width_cm' => 30,
                    'height_cm' => 20,
                    'price_per_unit_rmb' => 20,
                ]],
            ],
        ));

        $response->assertOk()->assertJson([
            'success' => true,
            'saved_directly' => true,
        ]);

        $product->refresh();
        $this->assertSame(12, (int) $product->pcs_per_box);
        $this->assertDatabaseMissing('barang_edit_requests', ['barang_id' => $barang->id]);
    }

    public function test_approver_also_submits_lwh_edit_request(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'pcs_per_box' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $approver = $this->userWithPermissions(['edit barang', 'approve barang', 'view barang']);

        $response = $this->actingAs($approver)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'pcs_per_box' => 10,
                    'length_cm' => 42,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ));

        $response->assertOk()->assertJson([
            'success' => true,
            'submitted_for_approval' => true,
        ]);
        $response->assertJsonMissing(['saved_directly' => true]);

        $this->assertDatabaseHas('barang_edit_requests', [
            'barang_id' => $barang->id,
            'submitted_by' => $approver->id,
            'status' => BarangEditRequest::STATUS_PENDING,
        ]);
    }

    public function test_approver_can_self_approve_edit_request(): void
    {
        $barang = $this->makeBarang(['notes' => 'Catatan awal']);
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'pcs_per_box' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $approver = $this->userWithPermissions(['edit barang', 'approve barang', 'view barang']);

        $this->actingAs($approver)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'pcs_per_box' => 10,
                    'length_cm' => 45,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->post(route('barang.edit-requests.approve', $editRequest))
            ->assertOk()
            ->assertJson(['success' => true]);

        $product->refresh();
        $this->assertSame('45.00', (string) $product->length_cm);
        $editRequest->refresh();
        $this->assertSame(BarangEditRequest::STATUS_APPROVED, $editRequest->status);
        $this->assertSame($approver->id, $editRequest->reviewed_by);
    }

    public function test_approve_applies_proposed_lwh_changes(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'pcs_per_box' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $approver = $this->userWithPermissions(['approve barang', 'reject barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'pcs_per_box' => 10,
                    'length_cm' => 41,
                    'width_cm' => 31,
                    'height_cm' => 21,
                ]],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->post(route('barang.edit-requests.approve', $editRequest))
            ->assertOk()
            ->assertJson(['success' => true]);

        $product->refresh();
        $this->assertSame('41.00', (string) $product->length_cm);
        $this->assertSame('31.00', (string) $product->width_cm);
        $this->assertSame('21.00', (string) $product->height_cm);
        $editRequest->refresh();
        $this->assertSame(BarangEditRequest::STATUS_APPROVED, $editRequest->status);
    }

    public function test_approve_applies_lwh_even_without_logistics_permission(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'pcs_per_box' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $approver = $this->userWithPermissions(['approve barang', 'reject barang', 'view barang', 'edit barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'pcs_per_box' => 10,
                    'length_cm' => 55,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->post(route('barang.edit-requests.approve', $editRequest))
            ->assertOk()
            ->assertJson(['success' => true]);

        $product->refresh();
        $this->assertSame('55.00', (string) $product->length_cm);
    }

    public function test_approve_lwh_change_does_not_fail_on_existing_no_resi_array(): void
    {
        $barang = $this->makeBarang(['no_resi' => ['790076058', '790076059']]);
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'pcs_per_box' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $approver = $this->userWithPermissions(['approve barang', 'reject barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'no_resi' => json_encode(['790076058', '790076059']),
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'pcs_per_box' => 10,
                    'length_cm' => 42,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->post(route('barang.edit-requests.approve', $editRequest))
            ->assertOk()
            ->assertJson(['success' => true]);

        $barang->refresh();
        $product->refresh();
        $this->assertSame(['790076058', '790076059'], $barang->no_resi);
        $this->assertSame('42.00', (string) $product->length_cm);
    }

    public function test_data_endpoint_returns_own_pending_for_submitter(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'length_cm' => 41,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ))->assertOk();

        $response = $this->actingAs($submitter)
            ->getJson(route('barang.edit-requests.data', ['status' => 'pending']));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'barang_id' => $barang->id,
            'status' => BarangEditRequest::STATUS_PENDING,
        ]);
    }

    public function test_data_endpoint_returns_all_pending_for_approver(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $approver = $this->userWithPermissions(['approve barang', 'reject barang', 'view barang', 'edit barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'length_cm' => 41,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ))->assertOk();

        $response = $this->actingAs($approver)->getJson(route('barang.edit-requests.data'));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['status' => BarangEditRequest::STATUS_PENDING]);
    }

    public function test_approver_sees_pending_without_team_marking_scope_filter(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $supervisor = User::factory()->create();
        Permission::findOrCreate('approve barang');
        Permission::findOrCreate('reject barang');
        Permission::findOrCreate('edit barang');
        Permission::findOrCreate('view barang');
        $supervisor->givePermissionTo(['approve barang', 'reject barang', 'edit barang', 'view barang']);
        $supervisorRole = \Spatie\Permission\Models\Role::findOrCreate('supervisor');
        $supervisor->assignRole($supervisorRole);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'length_cm' => 41,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ))->assertOk();

        $response = $this->actingAs($supervisor)->getJson(route('barang.edit-requests.data', ['status' => 'pending']));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['barang_id' => $barang->id]);
    }

    public function test_show_includes_product_field_diffs_and_hides_noisy_header_fields(): void
    {
        $barang = $this->makeBarang([
            'tanggal_masuk' => '2026-06-14',
            'price_per_cbm' => 1000,
            'weight_price' => 500,
            'price' => 10000,
        ]);

        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Item A',
            'material' => 'wood',
            'ctns' => 5,
            'pcs_per_box' => 10,
            'gross_weight_kg' => 12.5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'volume_cbm' => 0.024,
            'price_per_unit_rmb' => 18.5,
            'total_price_rmb' => 925,
        ]);

        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'tanggal_masuk' => '2026-06-14',
                'price_per_cbm' => 1000,
                'weight_price' => 500,
                'price' => 12000,
                'products' => [
                    [
                        'id' => $product->id,
                        'product_name' => 'Item A revised',
                        'material' => 'steel',
                        'ctns' => 8,
                        'pcs_per_box' => 12,
                        'gross_weight_kg' => 15,
                        'length_cm' => 41,
                        'width_cm' => 31,
                        'height_cm' => 21,
                        'price_per_unit_rmb' => 20,
                    ],
                ],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();
        $storedProducts = $editRequest->proposed_payload['products'] ?? [];
        $this->assertNotEmpty($storedProducts);
        $this->assertSame(8, (int) ($storedProducts[0]['ctns'] ?? 0));
        $this->assertSame('steel', (string) ($storedProducts[0]['material'] ?? ''));

        $response = $this->actingAs($submitter)
            ->getJson(route('barang.edit-requests.show', $editRequest));

        $response->assertOk();
        $diff = collect($response->json('diff'));
        $fields = $diff->pluck('field')->all();

        foreach ([
            'product_name',
            'material',
            'ctns',
            'pcs_per_box',
            'gross_weight_kg',
            'length_cm',
            'width_cm',
            'height_cm',
            'price_per_unit_rmb',
            'total_price_rmb',
            'volume_cbm',
        ] as $suffix) {
            $this->assertTrue(
                collect($fields)->contains(static fn (string $field): bool => str_contains($field, ".{$suffix}")),
                "Missing diff row for product field {$suffix}",
            );
        }

        $this->assertFalse($diff->contains(static fn (array $row): bool => ($row['field'] ?? '') === 'price'));
        $this->assertFalse($diff->contains(static fn (array $row): bool => ($row['field'] ?? '') === 'tanggal_masuk'));
        $this->assertFalse($diff->contains(static fn (array $row): bool => ($row['field'] ?? '') === 'price_per_cbm'));
    }

    public function test_reject_keeps_original_barang(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $approver = $this->userWithPermissions(['approve barang', 'reject barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'length_cm' => 99,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->post(route('barang.edit-requests.reject', $editRequest), [
                'rejection_note' => 'Data tidak sesuai',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $product->refresh();
        $this->assertSame('40.00', (string) $product->length_cm);
        $editRequest->refresh();
        $this->assertSame(BarangEditRequest::STATUS_REJECTED, $editRequest->status);
        $this->assertSame('Data tidak sesuai', $editRequest->rejection_note);
    }

    public function test_pending_edit_blocks_second_update(): void
    {
        $barang = $this->makeBarang();
        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);

        $payload = array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'length_cm' => 41,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        );

        $this->actingAs($submitter)->put(route('barang.update', $barang), $payload)->assertOk();

        $this->actingAs($submitter)
            ->put(route('barang.update', $barang), $payload)
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_foreign_product_id_is_rejected_on_submit(): void
    {
        $barang = $this->makeBarang();
        $otherBarang = $this->makeBarang(['no_index' => 'IDX-OTHER']);
        $foreignProduct = BarangProduct::query()->create([
            'barang_id' => $otherBarang->id,
            'sort_order' => 0,
            'product_name' => 'Foreign',
            'ctns' => 1,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
        ]);
        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);

        $this->actingAs($submitter)
            ->put(route('barang.update', $barang), array_merge(
                $this->baseUpdatePayload($barang),
                [
                    'products' => [[
                        'id' => $foreignProduct->id,
                        'product_name' => 'Foreign',
                        'ctns' => 1,
                        'length_cm' => 11,
                        'width_cm' => 10,
                        'height_cm' => 10,
                    ]],
                ],
            ))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseMissing('barang_edit_requests', ['barang_id' => $barang->id]);
    }

    public function test_approve_adds_new_product_and_removes_deleted_row(): void
    {
        $barang = $this->makeBarang();
        $productA = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box A',
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $productB = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 1,
            'product_name' => 'Box B',
            'ctns' => 3,
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
        ]);

        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $approver = $this->userWithPermissions(['approve barang', 'reject barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [
                    [
                        'id' => $productA->id,
                        'product_name' => 'Box A',
                        'ctns' => 5,
                        'length_cm' => 41,
                        'width_cm' => 30,
                        'height_cm' => 20,
                    ],
                    [
                        'product_name' => 'Box C',
                        'ctns' => 2,
                        'length_cm' => 60,
                        'width_cm' => 50,
                        'height_cm' => 40,
                    ],
                ],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->post(route('barang.edit-requests.approve', $editRequest))
            ->assertOk();

        $this->assertDatabaseMissing('barang_products', ['id' => $productB->id]);
        $this->assertDatabaseHas('barang_products', [
            'barang_id' => $barang->id,
            'product_name' => 'Box C',
            'length_cm' => 60,
        ]);
        $productA->refresh();
        $this->assertSame('41.00', (string) $productA->length_cm);
    }

    public function test_approve_preserves_existing_photo_on_lwh_only_change(): void
    {
        Storage::fake('public');

        $barang = $this->makeBarang();
        $photoPath = 'barang-products/existing-photo.jpg';
        Storage::disk('public')->put($photoPath, 'fake-image');

        $product = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Box 1',
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'photo_path' => $photoPath,
        ]);

        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $approver = $this->userWithPermissions(['approve barang', 'reject barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [[
                    'id' => $product->id,
                    'product_name' => 'Box 1',
                    'ctns' => 5,
                    'length_cm' => 42,
                    'width_cm' => 30,
                    'height_cm' => 20,
                ]],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->post(route('barang.edit-requests.approve', $editRequest))
            ->assertOk();

        $product->refresh();
        $this->assertSame($photoPath, $product->photo_path);
        Storage::disk('public')->assertExists($photoPath);
    }

    public function test_approve_adds_sub_item_under_parent(): void
    {
        $barang = $this->makeBarang();
        $parent = BarangProduct::query()->create([
            'barang_id' => $barang->id,
            'sort_order' => 0,
            'product_name' => 'Parent box',
            'ctns' => 4,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);

        $submitter = $this->userWithPermissions(['edit barang', 'view barang']);
        $approver = $this->userWithPermissions(['approve barang', 'reject barang', 'view barang']);

        $this->actingAs($submitter)->put(route('barang.update', $barang), array_merge(
            $this->baseUpdatePayload($barang),
            [
                'products' => [
                    [
                        'id' => $parent->id,
                        'product_name' => 'Parent box',
                        'ctns' => 4,
                        'length_cm' => 41,
                        'width_cm' => 30,
                        'height_cm' => 20,
                    ],
                    [
                        'is_sub_item' => 1,
                        'product_name' => 'Sub part',
                        'pcs_per_box' => 3,
                        'price_per_unit_rmb' => 5,
                    ],
                ],
            ],
        ))->assertOk();

        $editRequest = BarangEditRequest::query()->where('barang_id', $barang->id)->firstOrFail();

        $this->actingAs($approver)
            ->post(route('barang.edit-requests.approve', $editRequest))
            ->assertOk();

        $subItem = BarangProduct::query()
            ->where('barang_id', $barang->id)
            ->where('parent_id', $parent->id)
            ->first();

        $this->assertNotNull($subItem);
        $this->assertSame('Sub part', $subItem->product_name);
        $this->assertSame(3, (int) $subItem->pcs_per_box);
    }
}
