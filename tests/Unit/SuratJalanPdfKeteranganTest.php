<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\BarangProduct;
use App\Models\DeliveryTrip;
use App\Support\SuratJalanPdfData;
use ReflectionMethod;
use Tests\TestCase;

final class SuratJalanPdfKeteranganTest extends TestCase
{
    public function test_item_row_keterangan_is_blank_without_barang_keterangan(): void
    {
        $trip = new DeliveryTrip(['notes' => 'setest']);
        $barang = new Barang([
            'no_index' => 'A1',
            'ctns' => 5,
            'total_cbm' => 1.5,
            'total_gross_weight' => 100,
            'keterangan' => '',
            'notes' => 'setest',
        ]);
        $barang->setRelation('products', collect([
            new BarangProduct(['product_name' => 'Sandals', 'sort_order' => 1]),
        ]));

        $buildRows = new ReflectionMethod(SuratJalanPdfData::class, 'buildRows');
        $buildRows->setAccessible(true);

        $rows = $buildRows->invoke(null, $trip, collect([$barang]), [
            'trip_notes' => 'setest',
            'notes' => 'setest',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['keterangan']);
    }

    public function test_item_row_keterangan_uses_barang_keterangan_when_set(): void
    {
        $trip = new DeliveryTrip(['notes' => 'setest']);
        $barang = new Barang([
            'no_index' => 'A1',
            'ctns' => 5,
            'total_cbm' => 1.5,
            'total_gross_weight' => 100,
            'keterangan' => 'Fragile',
            'notes' => 'setest',
        ]);
        $barang->setRelation('products', collect([
            new BarangProduct(['product_name' => 'Sandals', 'sort_order' => 1]),
        ]));

        $buildRows = new ReflectionMethod(SuratJalanPdfData::class, 'buildRows');
        $buildRows->setAccessible(true);

        $rows = $buildRows->invoke(null, $trip, collect([$barang]), [
            'trip_notes' => 'setest',
            'notes' => 'setest',
        ]);

        $this->assertSame('Fragile', $rows[0]['keterangan']);
    }
}
