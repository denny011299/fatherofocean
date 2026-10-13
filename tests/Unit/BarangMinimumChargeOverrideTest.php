<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Marking;
use App\Support\BarangShippingPricing;
use ReflectionClass;
use Tests\TestCase;

final class BarangMinimumChargeOverrideTest extends TestCase
{
    public function test_resolve_minimum_charge_prefers_barang_override(): void
    {
        $barang = (new ReflectionClass(Barang::class))->newInstanceWithoutConstructor();
        $barang->setAttribute('marking', 'TEST/MARK');
        $barang->setAttribute('minimum_charge_cbm', 0.5);
        $barang->syncOriginal();

        // Force schema cache key so override path is used without DB.
        $ref = new ReflectionClass(Marking::class);
        $prop = $ref->getProperty('schemaColumnCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue();
        if (! is_array($cache)) {
            $cache = [];
        }
        $cache['barangs.minimum_charge_cbm'] = true;
        $prop->setValue(null, $cache);

        $this->assertSame(0.5, BarangShippingPricing::resolveMinimumChargeCbm($barang));
    }

    public function test_runtime_underscore_override_still_works(): void
    {
        $barang = (new ReflectionClass(Barang::class))->newInstanceWithoutConstructor();
        $barang->setAttribute('_minimum_charge_cbm', 1.25);

        $this->assertSame(1.25, BarangShippingPricing::resolveMinimumChargeCbm($barang));
    }
}
