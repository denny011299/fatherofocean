<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Container;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ContainerDepartureTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeContainer(?Carbon $etd): Container
    {
        $container = (new \ReflectionClass(Container::class))->newInstanceWithoutConstructor();
        $container->setAttribute('etd', $etd);

        return $container;
    }

    public function test_null_etd_is_not_departed_and_can_be_deleted(): void
    {
        $container = $this->makeContainer(null);

        $this->assertFalse($container->hasDeparted());
        $this->assertTrue($container->canBeDeleted());
    }

    public function test_etd_today_is_not_departed_and_can_be_deleted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 14:00:00'));

        $container = $this->makeContainer(Carbon::parse('2026-05-17'));

        $this->assertFalse($container->hasDeparted());
        $this->assertTrue($container->canBeDeleted());
    }

    public function test_etd_in_future_is_not_departed_and_can_be_deleted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 14:00:00'));

        $container = $this->makeContainer(Carbon::parse('2026-05-20'));

        $this->assertFalse($container->hasDeparted());
        $this->assertTrue($container->canBeDeleted());
    }

    public function test_etd_in_past_is_departed_and_cannot_be_deleted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 14:00:00'));

        $container = $this->makeContainer(Carbon::parse('2026-05-10'));

        $this->assertTrue($container->hasDeparted());
        $this->assertFalse($container->canBeDeleted());
    }
}
