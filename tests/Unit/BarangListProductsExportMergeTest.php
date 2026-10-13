<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exports\BarangListProductsExport;
use App\Models\Barang;
use App\Models\BarangProduct;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use ReflectionMethod;
use Tests\TestCase;

final class BarangListProductsExportMergeTest extends TestCase
{
    public function test_sub_products_share_same_merge_group_as_parent_coly(): void
    {
        $barang = new Barang;
        $barang->id = 99;

        $parent = new BarangProduct;
        $parent->id = 10;
        $parent->parent_id = null;

        $sub = new BarangProduct;
        $sub->id = 11;
        $sub->parent_id = 10;

        $this->assertSame('99:coly:10', $this->mergeGroup($barang, $parent, 'product'));
        $this->assertSame('99:coly:10', $this->mergeGroup($barang, $sub, 'product'));
    }

    public function test_separate_parent_colies_have_distinct_merge_groups(): void
    {
        $barang = new Barang;
        $barang->id = 100;

        $parentA = new BarangProduct;
        $parentA->id = 20;
        $parentA->parent_id = null;

        $parentB = new BarangProduct;
        $parentB->id = 30;
        $parentB->parent_id = null;

        $this->assertSame('100:coly:20', $this->mergeGroup($barang, $parentA, 'product'));
        $this->assertSame('100:coly:30', $this->mergeGroup($barang, $parentB, 'product'));
    }

    private function mergeGroup(Barang $barang, ?BarangProduct $product, string $kind): string
    {
        $export = new BarangListProductsExport(new EloquentCollection);
        $method = new ReflectionMethod($export, 'resolveMergeGroup');
        $method->setAccessible(true);

        return (string) $method->invoke($export, $barang, $product, $kind);
    }
}
