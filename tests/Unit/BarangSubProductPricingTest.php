<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use ReflectionMethod;
use Tests\TestCase;

final class BarangSubProductPricingTest extends TestCase
{
    public function test_sub_product_payload_carries_unit_price(): void
    {
        $method = new ReflectionMethod(Barang::class, 'productPayloadFromInputRow');
        $method->setAccessible(true);

        $payload = $method->invoke(null, [
            'product_name' => 'MIC Cable',
            'pcs_per_box' => 40,
            'price_per_unit_rmb' => 12.5,
        ], [
            'price_per_unit_rmb' => 99,
            'ctns' => 2,
        ], true);

        $this->assertSame('MIC Cable', $payload['product_name']);
        $this->assertSame(40, (int) $payload['pcs_per_box']);
        $this->assertSame(12.5, (float) $payload['price_per_unit_rmb']);
        $this->assertSame(2, (int) $payload['_parent_ctns']);
    }

    public function test_apply_product_calculations_for_sub_line_total(): void
    {
        $method = new ReflectionMethod(Barang::class, 'applyProductCalculations');
        $method->setAccessible(true);

        $payload = $method->invoke(null, [
            'ctns' => 0,
            'pcs_per_box' => 40,
            'price_per_unit_rmb' => 12.5,
            '_parent_ctns' => 2,
            'length_cm' => 0,
            'width_cm' => 0,
            'height_cm' => 0,
        ]);

        $this->assertSame(1000.0, (float) ($payload['total_price_rmb'] ?? 0));
    }

    public function test_apply_product_calculations_preserves_three_decimal_precision(): void
    {
        $method = new ReflectionMethod(Barang::class, 'applyProductCalculations');
        $method->setAccessible(true);

        $payload = $method->invoke(null, [
            'ctns' => 3,
            'pcs_per_box' => 10,
            'price_per_unit_rmb' => 1.234,
            'length_cm' => 0,
            'width_cm' => 0,
            'height_cm' => 0,
        ]);

        $this->assertSame(37.02, (float) $payload['total_price_rmb']);
    }
}
