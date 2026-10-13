<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BarangProduct;
use Tests\Concerns\BuildsBarangEditProducts;
use Tests\TestCase;

final class BarangEditRequestScenariosTest extends TestCase
{
    use BuildsBarangEditProducts;

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: callable}>
     */
    public static function payloadScenarios(): array
    {
        return [
            'tambah_sub_kedua' => [
                [
                    ['id' => 10, 'ctns' => 5, 'length_cm' => 41, 'width_cm' => 30, 'height_cm' => 20],
                    ['id' => 11, 'is_sub_item' => 1, 'product_name' => 'Sub A', 'pcs_per_box' => 3, 'price_per_unit_rmb' => 8],
                    ['is_sub_item' => 1, 'product_name' => 'Sub B', 'pcs_per_box' => 2, 'price_per_unit_rmb' => 5],
                ],
                [
                    ['id' => 10, 'ctns' => 5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20, 'parent_id' => null],
                    ['id' => 11, 'product_name' => 'Sub A', 'pcs_per_box' => 3, 'parent_id' => 10, 'is_sub_item' => true, 'photo_path' => 'barang-products/a.jpg'],
                ],
                static function (array $built): void {
                    self::assertCount(3, $built);
                    self::assertSame(11, (int) $built[1]['id']);
                    self::assertSame(1, (int) ($built[1]['is_sub_item'] ?? 0));
                    self::assertNull($built[2]['id'] ?? null);
                    self::assertSame('Sub B', $built[2]['product_name'] ?? null);
                    self::assertSame('barang-products/a.jpg', $built[1]['photo_path'] ?? null);
                },
            ],
            'tambah_parent_tanpa_sub' => [
                [
                    ['id' => 10, 'ctns' => 5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20],
                    ['product_name' => 'Box baru', 'ctns' => 3, 'length_cm' => 55, 'width_cm' => 45, 'height_cm' => 35],
                ],
                [
                    ['id' => 10, 'ctns' => 5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20, 'parent_id' => null],
                ],
                static function (array $built): void {
                    self::assertCount(2, $built);
                    self::assertArrayNotHasKey('is_sub_item', $built[1]);
                    self::assertSame('55', (string) ($built[1]['length_cm'] ?? ''));
                },
            ],
            'parent_sub_plus_parent_baru' => [
                [
                    ['id' => 10, 'ctns' => 4, 'length_cm' => 41, 'width_cm' => 30, 'height_cm' => 20],
                    ['id' => 11, 'is_sub_item' => 1, 'product_name' => 'Sub', 'pcs_per_box' => 4, 'price_per_unit_rmb' => 10],
                    ['product_name' => 'Box 2', 'ctns' => 2, 'length_cm' => 60, 'width_cm' => 50, 'height_cm' => 40],
                ],
                [
                    ['id' => 10, 'ctns' => 4, 'length_cm' => 40, 'parent_id' => null],
                    ['id' => 11, 'parent_id' => 10, 'is_sub_item' => true, 'photo_path' => 'barang-products/sub.jpg'],
                ],
                static function (array $built): void {
                    self::assertCount(3, $built);
                    self::assertSame('41', (string) ($built[0]['length_cm'] ?? ''));
                    self::assertSame('barang-products/sub.jpg', $built[1]['photo_path'] ?? null);
                    self::assertSame('60', (string) ($built[2]['length_cm'] ?? ''));
                },
            ],
            'ganti_lwh_tambah_sub_dan_box' => [
                [
                    ['id' => 10, 'ctns' => 5, 'length_cm' => 45, 'width_cm' => 31, 'height_cm' => 21],
                    ['is_sub_item' => 1, 'product_name' => 'Sub baru', 'pcs_per_box' => 6, 'price_per_unit_rmb' => 12],
                    ['product_name' => 'Box C', 'ctns' => 1, 'length_cm' => 70, 'width_cm' => 60, 'height_cm' => 50],
                ],
                [
                    ['id' => 10, 'ctns' => 5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20, 'parent_id' => null],
                ],
                static function (array $built): void {
                    self::assertCount(3, $built);
                    self::assertSame('45', (string) ($built[0]['length_cm'] ?? ''));
                    self::assertSame(1, (int) ($built[1]['is_sub_item'] ?? 0));
                    self::assertSame(360.0, (float) ($built[1]['total_price_rmb'] ?? 0));
                },
            ],
        ];
    }

    /**
     * @dataProvider payloadScenarios
     * @param  list<array<string, mixed>>  $proposed
     * @param  list<array<string, mixed>>  $snapshot
     */
    public function test_build_proposed_payload_scenarios(
        array $proposed,
        array $snapshot,
        callable $assert,
    ): void {
        $built = $this->buildProposedProducts($proposed, $snapshot);
        $assert($built);
    }

    public function test_ordered_for_form_keeps_subs_under_each_parent(): void
    {
        $parent = $this->parentRow(10);
        $subA = array_merge($this->subRow(['product_name' => 'A']), ['id' => 11, 'parent_id' => 10, 'sort_order' => 1]);
        $parentB = $this->parentRow(20, ['sort_order' => 2, 'ctns' => 3]);
        $subB = array_merge($this->subRow(['product_name' => 'B']), ['id' => 21, 'parent_id' => 20, 'sort_order' => 0]);

        $ordered = BarangProduct::orderedForForm([
            $this->model($subB),
            $this->model($parentB),
            $this->model($subA),
            $this->model($parent),
        ]);

        $this->assertSame([10, 11, 20, 21], $ordered->pluck('id')->all());
    }

    /** @param  array<string, mixed>  $attrs */
    private function model(array $attrs): BarangProduct
    {
        $m = new BarangProduct($attrs);
        $m->id = (int) $attrs['id'];

        return $m;
    }
}
