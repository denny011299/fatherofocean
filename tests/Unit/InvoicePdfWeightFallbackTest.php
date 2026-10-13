<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Support\InvoicePdfData;
use ReflectionMethod;
use Tests\TestCase;

final class InvoicePdfWeightFallbackTest extends TestCase
{
    public function test_product_weight_falls_back_to_barang_total_gross_weight(): void
    {
        $barang = new Barang([
            'no_index' => 'IDX-1',
            'total_cbm' => 0.5,
            'total_gross_weight' => 120,
            'ctns' => 2,
            'price_per_cbm' => 5_000_000,
        ]);
        $product = new BarangProduct([
            'product_name' => 'Item A',
            'ctns' => 2,
            'sort_order' => 1,
            'length_cm' => 50,
            'width_cm' => 40,
            'height_cm' => 30,
            'gross_weight_kg' => 0,
        ]);
        $barang->setRelation('products', collect([$product]));
        $barang->setRelation('additionalPrices', collect());

        $rows = $this->productRows($barang);

        $this->assertSame('60', $rows[0]['unit_weight']);
        $this->assertSame('120', $rows[0]['total_weight']);
    }

    public function test_zero_weight_shows_zero_not_dash(): void
    {
        $barang = new Barang([
            'no_index' => 'IDX-2',
            'total_cbm' => 0.1,
            'total_gross_weight' => 0,
            'ctns' => 1,
            'price_per_cbm' => 5_000_000,
        ]);
        $product = new BarangProduct([
            'product_name' => 'Item B',
            'ctns' => 1,
            'sort_order' => 1,
            'length_cm' => 10,
            'width_cm' => 10,
            'height_cm' => 10,
            'gross_weight_kg' => 0,
        ]);
        $barang->setRelation('products', collect([$product]));
        $barang->setRelation('additionalPrices', collect());

        $rows = $this->productRows($barang);

        $this->assertSame('0', $rows[0]['unit_weight']);
        $this->assertSame('0', $rows[0]['total_weight']);
    }

    /** @return list<array<string, mixed>> */
    private function productRows(Barang $barang): array
    {
        $method = new ReflectionMethod(InvoicePdfData::class, 'buildTableRowsFromBarangs');
        $method->setAccessible(true);

        return $method->invoke(null, collect([$barang]));
    }
}
