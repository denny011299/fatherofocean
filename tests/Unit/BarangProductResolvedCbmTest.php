<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BarangProduct;
use PHPUnit\Framework\TestCase;

final class BarangProductResolvedCbmTest extends TestCase
{
    public function test_resolved_unit_cbm_divides_by_one_thousand(): void
    {
        $product = new BarangProduct([
            'length_cm' => 14,
            'width_cm' => 15,
            'height_cm' => 6,
            'ctns' => 2,
            'volume_cbm' => 0,
        ]);

        $this->assertSame(1.26, $product->resolvedUnitCbm());
        $this->assertSame(2.52, $product->resolvedLineCbm());
    }

    public function test_resolved_cbm_ignores_stale_volume_cbm_column(): void
    {
        $product = new BarangProduct([
            'length_cm' => 50,
            'width_cm' => 50,
            'height_cm' => 50,
            'ctns' => 0,
            'volume_cbm' => 0.001,
        ]);

        $this->assertSame(125.0, $product->resolvedUnitCbm());
        $this->assertSame(0.0, $product->resolvedLineCbm());
    }
}
