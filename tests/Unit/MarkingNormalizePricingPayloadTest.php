<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\MarkingController;
use Tests\TestCase;

final class MarkingNormalizePricingPayloadTest extends TestCase
{
    public function test_normalize_pricing_payload_clears_empty_effective_start(): void
    {
        $method = new \ReflectionMethod(MarkingController::class, 'normalizePricingPayload');
        $method->setAccessible(true);

        $normalized = $method->invoke(null, [
            'effective_start' => '',
            'customer_name' => 'Test',
        ]);

        $this->assertNull($normalized['effective_start']);
    }
}
