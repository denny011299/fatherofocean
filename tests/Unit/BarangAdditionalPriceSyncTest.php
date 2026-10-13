<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\BarangController;
use ReflectionMethod;
use Tests\TestCase;

final class BarangAdditionalPriceSyncTest extends TestCase
{
    public function test_parse_money_input_handles_idr_and_plain_numbers(): void
    {
        $controller = new ReflectionMethod(BarangController::class, 'parseMoneyInput');
        $controller->setAccessible(true);
        $instance = (new \ReflectionClass(BarangController::class))->newInstanceWithoutConstructor();

        $this->assertSame(40000.0, $controller->invoke($instance, 'Rp 40.000'));
        $this->assertSame(40000.0, $controller->invoke($instance, '40.000'));
        $this->assertSame(40000.5, $controller->invoke($instance, '40.000,50'));
        $this->assertSame(40000.0, $controller->invoke($instance, '40000'));
        $this->assertSame(40000.5, $controller->invoke($instance, '40000.50'));
        $this->assertSame(25000.0, $controller->invoke($instance, 25000));
        $this->assertSame(0.0, $controller->invoke($instance, ''));
        $this->assertSame(0.0, $controller->invoke($instance, null));
    }
}
