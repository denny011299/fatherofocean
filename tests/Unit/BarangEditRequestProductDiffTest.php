<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BarangEditRequest;
use App\Support\BarangEditRequestService;
use Tests\TestCase;

final class BarangEditRequestProductDiffTest extends TestCase
{
    public function test_diff_lists_gw_and_cbm_summary_for_main_product_cbm_weight_changes(): void
    {
        $editRequest = new BarangEditRequest([
            'current_snapshot' => [
                'products' => [[
                    'id' => 10,
                    'product_name' => 'test',
                    'material' => 'wood',
                    'ctns' => 5,
                    'pcs_per_box' => 10,
                    'gross_weight_kg' => 12.5,
                    'length_cm' => 40,
                    'width_cm' => 30,
                    'height_cm' => 20,
                    'volume_cbm' => 0.12,
                    'price_per_unit_rmb' => 18.5,
                    'total_price_rmb' => 925,
                    'parent_id' => null,
                    'is_sub_item' => false,
                ]],
            ],
            'proposed_payload' => [
                'products' => [[
                    'id' => 10,
                    'product_name' => 'test1',
                    'material' => 'steel',
                    'ctns' => 8,
                    'pcs_per_box' => 12,
                    'gross_weight_kg' => 15,
                    'length_cm' => 41,
                    'width_cm' => 31,
                    'height_cm' => 21,
                    'price_per_unit_rmb' => 20,
                ]],
            ],
        ]);

        $diff = app(BarangEditRequestService::class)->diffRows($editRequest);
        $fields = collect($diff)->pluck('field')->all();

        foreach ([
            'product.10.gross_weight_kg',
            'product.10.volume_cbm',
        ] as $expectedField) {
            $this->assertContains($expectedField, $fields, "Missing diff for {$expectedField}");
        }

        $this->assertNotContains('product.10.product_name', $fields);
        $this->assertNotContains('product.10.length_cm', $fields);
    }

    public function test_diff_detects_sparse_name_only_change(): void
    {
        $editRequest = new BarangEditRequest([
            'current_snapshot' => [
                'products' => [[
                    'id' => 10,
                    'product_name' => 'test',
                    'ctns' => 5,
                    'pcs_per_box' => 10,
                    'gross_weight_kg' => 12.5,
                    'length_cm' => 40,
                    'width_cm' => 30,
                    'height_cm' => 20,
                    'volume_cbm' => 0.12,
                    'price_per_unit_rmb' => 18.5,
                    'total_price_rmb' => 925,
                    'parent_id' => null,
                    'is_sub_item' => false,
                ]],
            ],
            'proposed_payload' => [
                'products' => [[
                    'id' => 10,
                    'product_name' => 'test1',
                ]],
            ],
        ]);

        $diff = app(BarangEditRequestService::class)->diffRows($editRequest);
        $fields = collect($diff)->pluck('field')->all();

        $this->assertSame(['product.10.product_name'], $fields);
    }

    public function test_diff_detects_sub_item_field_changes(): void
    {
        $editRequest = new BarangEditRequest([
            'current_snapshot' => [
                'products' => [[
                    'id' => 11,
                    'product_name' => 'sub-a',
                    'material' => null,
                    'pcs_per_box' => 5,
                    'price_per_unit_rmb' => 10,
                    'total_price_rmb' => 50,
                    'parent_id' => 10,
                    'is_sub_item' => true,
                ]],
            ],
            'proposed_payload' => [
                'products' => [[
                    'id' => 11,
                    'is_sub_item' => 1,
                    'parent_id' => 10,
                    'product_name' => 'sub-b',
                    'pcs_per_box' => 7,
                    'price_per_unit_rmb' => 12,
                ]],
            ],
        ]);

        $diff = app(BarangEditRequestService::class)->diffRows($editRequest);
        $fields = collect($diff)->pluck('field')->all();

        $this->assertContains('product.11.product_name', $fields);
        $this->assertContains('product.11.pcs_per_box', $fields);
        $this->assertContains('product.11.price_per_unit_rmb', $fields);
        $this->assertContains('product.11.total_price_rmb', $fields);
    }

    public function test_requires_approval_only_when_lwh_changes(): void
    {
        $service = app(BarangEditRequestService::class);
        $hasCbmWeight = new \ReflectionMethod($service, 'hasProductCbmWeightChanges');
        $hasCbmWeight->setAccessible(true);

        $snapshot = [[
            'id' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'pcs_per_box' => 10,
            'ctns' => 5,
            'gross_weight_kg' => 20,
        ]];

        $pcsOnly = [[
            'id' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'pcs_per_box' => 12,
            'ctns' => 5,
            'gross_weight_kg' => 20,
        ]];

        $lwhChanged = [[
            'id' => 10,
            'length_cm' => 41,
            'width_cm' => 30,
            'height_cm' => 20,
            'pcs_per_box' => 10,
            'ctns' => 5,
            'gross_weight_kg' => 20,
        ]];

        $weightChanged = [[
            'id' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'pcs_per_box' => 10,
            'ctns' => 5,
            'gross_weight_kg' => 25,
        ]];

        $this->assertFalse($hasCbmWeight->invoke($service, $snapshot, $pcsOnly));
        $this->assertTrue($hasCbmWeight->invoke($service, $snapshot, $lwhChanged));
        $this->assertTrue($hasCbmWeight->invoke($service, $snapshot, $weightChanged));
    }

    public function test_snapshot_preserves_photo_paths_in_proposed_payload(): void
    {
        $service = app(BarangEditRequestService::class);

        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $snapshotProducts = [[
            'id' => 10,
            'photo_path' => 'barang-products/photo.jpg',
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'ctns' => 5,
        ]];

        $built = $reflection->invoke($service, [[
            'id' => 10,
            'length_cm' => 41,
            'width_cm' => 30,
            'height_cm' => 20,
            'ctns' => 5,
        ]], $snapshotProducts);

        $this->assertSame('barang-products/photo.jpg', $built[0]['photo_path'] ?? null);
    }

    public function test_build_rejects_foreign_product_id(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $this->expectException(\RuntimeException::class);

        $reflection->invoke($service, [[
            'id' => 999,
            'length_cm' => 41,
        ]], [[
            'id' => 10,
            'length_cm' => 40,
        ]]);
    }

    public function test_build_allows_new_product_without_id(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $built = $reflection->invoke($service, [[
            'product_name' => 'New box',
            'ctns' => 3,
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
        ]], [[
            'id' => 10,
            'length_cm' => 40,
        ]]);

        $this->assertCount(1, $built);
        $this->assertSame('New box', $built[0]['product_name'] ?? null);
        $this->assertArrayNotHasKey('id', $built[0]);
    }

    public function test_assert_replay_rejects_tampered_product_id(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'assertReplayProductsBelongToSnapshot');
        $reflection->setAccessible(true);

        $editRequest = new BarangEditRequest([
            'current_snapshot' => [
                'products' => [[
                    'id' => 10,
                    'length_cm' => 40,
                ]],
            ],
            'proposed_payload' => [
                'products' => [[
                    'id' => 99,
                    'length_cm' => 41,
                ]],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $reflection->invoke($service, $editRequest);
    }

    public function test_product_removal_does_not_require_approval(): void
    {
        $service = app(BarangEditRequestService::class);
        $hasCbmWeight = new \ReflectionMethod($service, 'hasProductCbmWeightChanges');
        $hasCbmWeight->setAccessible(true);

        $snapshot = [[
            'id' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ], [
            'id' => 11,
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
        ]];

        $onlyFirstKept = [[
            'id' => 10,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]];

        $this->assertFalse($hasCbmWeight->invoke($service, $snapshot, $onlyFirstKept));
    }

    public function test_sub_item_total_price_uses_parent_ctns(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $built = $reflection->invoke($service, [
            [
                'id' => 10,
                'ctns' => 5,
                'length_cm' => 40,
                'width_cm' => 30,
                'height_cm' => 20,
            ],
            [
                'is_sub_item' => 1,
                'product_name' => 'Accessory',
                'pcs_per_box' => 2,
                'price_per_unit_rmb' => 10,
            ],
        ], [[
            'id' => 10,
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]]);

        $this->assertCount(2, $built);
        $this->assertSame(100.0, (float) ($built[1]['total_price_rmb'] ?? 0));
    }

    public function test_requires_approval_for_new_parent_box_row(): void
    {
        $service = app(BarangEditRequestService::class);
        $hasCbmWeight = new \ReflectionMethod($service, 'hasProductCbmWeightChanges');
        $hasCbmWeight->setAccessible(true);

        $this->assertFalse($hasCbmWeight->invoke($service, [], [[
            'product_name' => 'Box baru',
            'ctns' => 2,
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
        ]]));
    }

    public function test_merge_staged_paths_into_products(): void
    {
        $service = app(BarangEditRequestService::class);
        $merge = new \ReflectionMethod($service, 'mergeStagedPathsIntoProducts');
        $merge->setAccessible(true);

        $merged = $merge->invoke($service, [[
            'product_name' => 'Sub',
            'is_sub_item' => 1,
        ]], [
            'products' => [
                0 => ['photo' => 'barang-edit-requests/token/products/abc.jpg'],
            ],
        ]);

        $this->assertSame('barang-edit-requests/token/products/abc.jpg', $merged[0]['photo_path'] ?? null);
    }

    public function test_build_proposed_preserves_sub_photo_when_parent_lwh_changes(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $snapshot = [
            [
                'id' => 10,
                'ctns' => 5,
                'length_cm' => 40,
                'width_cm' => 30,
                'height_cm' => 20,
                'photo_path' => null,
                'parent_id' => null,
            ],
            [
                'id' => 11,
                'product_name' => 'Sub item',
                'pcs_per_box' => 4,
                'price_per_unit_rmb' => 10,
                'photo_path' => 'barang-products/sub-photo.jpg',
                'parent_id' => 10,
                'is_sub_item' => true,
            ],
        ];

        $built = $reflection->invoke($service, [
            [
                'id' => 10,
                'ctns' => 5,
                'length_cm' => 42,
                'width_cm' => 30,
                'height_cm' => 20,
                'is_sub_item' => 0,
            ],
            [
                'id' => 11,
                'is_sub_item' => 1,
                'product_name' => 'Sub item',
                'pcs_per_box' => 4,
                'price_per_unit_rmb' => 10,
            ],
        ], $snapshot);

        $this->assertCount(2, $built);
        $this->assertSame('42', (string) ($built[0]['length_cm'] ?? ''));
        $this->assertSame('barang-products/sub-photo.jpg', $built[1]['photo_path'] ?? null);
        $this->assertSame(1, (int) ($built[1]['is_sub_item'] ?? 0));
        $this->assertSame(11, (int) ($built[1]['id'] ?? 0));
    }

    public function test_build_proposed_carries_remove_photo_flag_for_replay(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $built = $reflection->invoke($service, [[
            'id' => 10,
            'ctns' => 5,
            'length_cm' => 41,
            'width_cm' => 30,
            'height_cm' => 20,
            'remove_photo' => '1',
        ]], [[
            'id' => 10,
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'photo_path' => 'barang-products/old.jpg',
        ]]);

        $this->assertArrayHasKey('photo_path', $built[0]);
        $this->assertNull($built[0]['photo_path']);
        $this->assertSame('1', (string) ($built[0]['remove_photo'] ?? ''));
    }

    public function test_build_proposed_clears_parent_photo_when_sub_items_exist(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $snapshot = [
            [
                'id' => 10,
                'ctns' => 5,
                'length_cm' => 40,
                'width_cm' => 30,
                'height_cm' => 20,
                'photo_path' => 'barang-products/parent.jpg',
            ],
            [
                'id' => 11,
                'parent_id' => 10,
                'is_sub_item' => true,
                'product_name' => 'Sub item',
                'pcs_per_box' => 4,
                'photo_path' => 'barang-products/sub.jpg',
            ],
        ];

        $built = $reflection->invoke($service, [
            [
                'id' => 10,
                'ctns' => 5,
                'length_cm' => 42,
                'width_cm' => 30,
                'height_cm' => 20,
            ],
            [
                'id' => 11,
                'is_sub_item' => 1,
                'product_name' => 'Sub item',
                'pcs_per_box' => 4,
            ],
        ], $snapshot);

        $this->assertArrayHasKey('photo_path', $built[0]);
        $this->assertNull($built[0]['photo_path']);
        $this->assertSame('1', (string) ($built[0]['remove_photo'] ?? ''));
        $this->assertSame('barang-products/sub.jpg', $built[1]['photo_path'] ?? null);
    }

    public function test_build_proposed_honors_explicit_empty_photo_path(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $built = $reflection->invoke($service, [[
            'id' => 10,
            'ctns' => 5,
            'length_cm' => 41,
            'width_cm' => 30,
            'height_cm' => 20,
            'photo_path' => '',
        ]], [[
            'id' => 10,
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'photo_path' => 'barang-products/old.jpg',
        ]]);

        $this->assertArrayHasKey('photo_path', $built[0]);
        $this->assertNull($built[0]['photo_path']);
    }

    public function test_build_proposed_normalizes_new_sub_and_parent_rows(): void
    {
        $service = app(BarangEditRequestService::class);
        $reflection = new \ReflectionMethod($service, 'buildProposedProducts');
        $reflection->setAccessible(true);

        $built = $reflection->invoke($service, [
            [
                'id' => 10,
                'ctns' => 5,
                'length_cm' => 42,
                'width_cm' => 30,
                'height_cm' => 20,
            ],
            [
                'is_sub_item' => 1,
                'product_name' => 'Sub baru',
                'pcs_per_box' => 6,
                'price_per_unit_rmb' => 12,
            ],
            [
                'product_name' => 'Box baru',
                'ctns' => 1,
                'length_cm' => 70,
                'width_cm' => 60,
                'height_cm' => 50,
            ],
        ], [[
            'id' => 10,
            'ctns' => 5,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
            'parent_id' => null,
        ]]);

        $this->assertCount(3, $built);
        $this->assertArrayNotHasKey('is_sub_item', $built[0]);
        $this->assertSame(1, (int) ($built[1]['is_sub_item'] ?? 0));
        $this->assertArrayNotHasKey('parent_id', $built[1]);
        $this->assertArrayNotHasKey('is_sub_item', $built[2]);
        $this->assertSame('Sub baru', $built[1]['product_name'] ?? null);
        $this->assertSame('Box baru', $built[2]['product_name'] ?? null);
    }

    public function test_requires_approval_for_new_sub_item_without_lwh_change(): void
    {
        $service = app(BarangEditRequestService::class);
        $hasCbmWeight = new \ReflectionMethod($service, 'hasProductCbmWeightChanges');
        $hasCbmWeight->setAccessible(true);

        $needsApproval = $hasCbmWeight->invoke($service, [
            ['id' => 10, 'ctns' => 5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20],
        ], [
            ['id' => 10, 'ctns' => 5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20],
            ['is_sub_item' => 1, 'product_name' => 'Sub baru', 'pcs_per_box' => 4, 'price_per_unit_rmb' => 10],
        ]);

        $this->assertFalse($needsApproval);
    }
}
