<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterPricingItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class MarkingCurrentEffectivePricingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function makePricing(array $attributes, ?MasterPricingItem $item = null): MarkingPricing
    {
        $pricing = (new \ReflectionClass(MarkingPricing::class))->newInstanceWithoutConstructor();
        foreach ($attributes as $key => $value) {
            $pricing->setAttribute($key, $value);
        }
        if ($item !== null) {
            $pricing->setRelation('pricingItem', $item);
        }

        return $pricing;
    }

    /** @param array<string, mixed> $attributes */
    private function makeMarking(array $attributes, Collection $pricings): Marking
    {
        $marking = (new \ReflectionClass(Marking::class))->newInstanceWithoutConstructor();
        foreach ($attributes as $key => $value) {
            $marking->setAttribute($key, $value);
        }
        $marking->setRelation('pricings', $pricings);

        return $marking;
    }

    private function masterItem(string $volume, string $gw): MasterPricingItem
    {
        $item = new MasterPricingItem();
        $item->setAttribute('volume_price', $volume);
        $item->setAttribute('gw_price', $gw);

        return $item;
    }

    public function test_current_effective_pricing_includes_row_when_marking_effective_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-17 10:00:00', 'Asia/Jakarta'));

        $marking = $this->makeMarking(
            ['effective_start' => '2026-05-17'],
            new Collection([
                $this->makePricing([
                    'id' => 1,
                    'volume_price' => '500000.00',
                    'gw_price' => '10000.00',
                ]),
            ]),
        );

        $effective = $marking->currentEffectivePricing();

        $this->assertNotNull($effective);
        $this->assertSame('500000.00', (string) $effective->volume_price);
        $this->assertSame('10000.00', (string) $effective->gw_price);
    }

    public function test_current_effective_pricing_excludes_when_marking_start_is_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 10:00:00', 'Asia/Jakarta'));

        $marking = $this->makeMarking(
            ['effective_start' => '2026-05-17'],
            new Collection([
                $this->makePricing([
                    'id' => 1,
                    'volume_price' => '500000.00',
                    'gw_price' => '10000.00',
                ]),
            ]),
        );

        $this->assertNull($marking->currentEffectivePricing());
    }

    public function test_current_effective_pricing_returns_highest_id_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00', 'Asia/Jakarta'));

        $marking = $this->makeMarking(
            ['effective_start' => '2026-05-01'],
            new Collection([
                $this->makePricing(['id' => 1, 'volume_price' => '100.00', 'gw_price' => '10.00']),
                $this->makePricing(['id' => 2, 'volume_price' => '500000.00', 'gw_price' => '10000.00']),
            ]),
        );

        $effective = $marking->currentEffectivePricing();

        $this->assertNotNull($effective);
        $this->assertSame(2, $effective->id);
    }
}
