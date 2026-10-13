<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangProduct;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

final class BarangProductListCompletenessTest extends TestCase
{
    public function test_empty_product_list_is_incomplete(): void
    {
        $barang = new Barang;
        $barang->setRelation('products', new EloquentCollection);

        $this->assertFalse($barang->hasCompleteProductList());
    }

    public function test_parent_missing_photo_is_incomplete(): void
    {
        $barang = new Barang;
        $barang->setRelation('products', new EloquentCollection([
            new BarangProduct([
                'product_name' => 'Router',
                'ctns' => 2,
                'gross_weight_kg' => 10,
                'length_cm' => 50,
                'width_cm' => 40,
                'height_cm' => 30,
            ]),
        ]));

        $this->assertFalse($barang->hasCompleteProductList());
    }

    public function test_complete_parent_and_sub_products(): void
    {
        $parent = new BarangProduct([
            'product_name' => 'Box A',
            'ctns' => 2,
            'gross_weight_kg' => 10,
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
            'photo_path' => 'barang-products/parent.jpg',
        ]);
        $parent->id = 1;

        $sub = new BarangProduct([
            'parent_id' => 1,
            'product_name' => 'Cable',
        ]);
        $sub->id = 2;

        $barang = new Barang;
        $barang->setRelation('products', new EloquentCollection([$parent, $sub]));

        $this->assertTrue($barang->hasCompleteProductList());
    }

    public function test_complete_parent_with_subs_does_not_require_parent_name_or_photo(): void
    {
        $parent = new BarangProduct([
            'product_name' => null,
            'ctns' => 2,
            'gross_weight_kg' => 10,
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
        ]);
        $parent->id = 1;

        $sub = new BarangProduct([
            'parent_id' => 1,
            'product_name' => 'Cable',
        ]);
        $sub->id = 2;

        $barang = new Barang;
        $barang->setRelation('products', new EloquentCollection([$parent, $sub]));

        $this->assertTrue($barang->hasCompleteProductList());
    }

    public function test_sub_product_without_name_is_incomplete(): void
    {
        $parent = new BarangProduct([
            'id' => 1,
            'product_name' => 'Box A',
            'ctns' => 1,
            'gross_weight_kg' => 5,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'photo_path' => 'barang-products/parent.jpg',
        ]);

        $sub = new BarangProduct([
            'parent_id' => 1,
        ]);

        $barang = new Barang;
        $barang->setRelation('products', new EloquentCollection([$parent, $sub]));

        $this->assertFalse($barang->hasCompleteProductList());
    }

    public function test_items_summary_uses_sub_names_when_parent_has_no_name(): void
    {
        $parent = new BarangProduct([
            'product_name' => null,
            'ctns' => 3,
            'gross_weight_kg' => 39.1,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 20,
        ]);
        $parent->id = 1;

        $subA = new BarangProduct([
            'parent_id' => 1,
            'product_name' => 'Stempel Kayu',
        ]);
        $subA->id = 2;

        $subB = new BarangProduct([
            'parent_id' => 1,
            'product_name' => 'Stiker',
        ]);
        $subB->id = 3;

        $barang = new Barang;
        $barang->setRelation('products', new EloquentCollection([$parent, $subA, $subB]));

        $this->assertSame('Stempel Kayu, Stiker', $barang->itemsSummary());
    }
}
