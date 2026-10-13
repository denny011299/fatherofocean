<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exports\BarangProductsExport;
use App\Models\Barang;
use App\Models\BarangProduct;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use ReflectionMethod;
use Tests\TestCase;

final class BarangProductsExportGroupsTest extends TestCase
{
    public function test_product_groups_merge_sub_items_under_one_coly(): void
    {
        $barang = new Barang;
        $parent = new BarangProduct([
            'sort_order' => 1,
            'ctns' => 1,
            'product_name' => 'ccoba',
            'gross_weight_kg' => 600,
            'length_cm' => 100,
            'width_cm' => 100,
            'height_cm' => 100,
            'volume_cbm' => 1.0,
            'total_price_rmb' => 100,
        ]);
        $parent->id = 10;

        $subA = new BarangProduct([
            'parent_id' => 10,
            'sort_order' => 2,
            'product_name' => 'MIC Cable',
            'pcs_per_box' => 40,
            'price_per_unit_rmb' => 10,
            'total_price_rmb' => 400,
        ]);
        $subA->id = 11;
        $subA->setRelation('parent', $parent);

        $subB = new BarangProduct([
            'parent_id' => 10,
            'sort_order' => 3,
            'product_name' => 'hidung besar',
            'pcs_per_box' => 10,
            'price_per_unit_rmb' => 10,
            'total_price_rmb' => 100,
        ]);
        $subB->id = 12;
        $subB->setRelation('parent', $parent);

        $barang->setRelation('products', new EloquentCollection([$parent, $subA, $subB]));

        $export = (new \ReflectionClass(BarangProductsExport::class))->newInstanceWithoutConstructor();
        $barangProperty = new \ReflectionProperty(BarangProductsExport::class, 'barang');
        $barangProperty->setAccessible(true);
        $barangProperty->setValue($export, $barang);

        $method = new ReflectionMethod($export, 'buildProductGroups');
        $method->setAccessible(true);
        $groups = $method->invoke($export);

        $this->assertCount(1, $groups);
        $this->assertCount(3, $groups[0]['items']);
        $this->assertSame(1, (int) $groups[0]['master']->ctns);
        $this->assertSame('MIC Cable', $groups[0]['items'][1]->product_name);
    }
}
