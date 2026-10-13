<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Support\CbmFormat;
use App\Support\CbmVolume;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BarangProductCbmTest extends TestCase
{
    public function test_apply_product_calculations_rounds_volume_cbm_to_three_decimals(): void
    {
        $payload = [
            'ctns' => 10,
            'length_cm' => 33.33,
            'width_cm' => 33.33,
            'height_cm' => 33.33,
        ];

        $result = $this->invokeApplyProductCalculations($payload);

        $expected = CbmVolume::line(33.33, 33.33, 33.33, 10);
        $this->assertSame($expected, (float) $result['volume_cbm']);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function invokeApplyProductCalculations(array $payload): array
    {
        $method = new ReflectionMethod(Barang::class, 'applyProductCalculations');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke(null, $payload);

        return $result;
    }
}
