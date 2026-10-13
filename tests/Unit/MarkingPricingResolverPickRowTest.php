<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterPricingItem;
use App\Support\MarkingPricingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarkingPricingResolverPickRowTest extends TestCase
{
    use RefreshDatabase;

    public function test_picks_row_by_pricing_item_id(): void
    {
        $marking = Marking::query()->create([
            'code' => 'PICK-ID',
            'customer_name' => 'Pick Customer',
        ]);

        $umum = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 5_500_000,
            'gw_price' => 11_000,
            'is_active' => true,
        ]);
        $shoes = MasterPricingItem::query()->create([
            'name' => 'Sepatu Non Brand',
            'volume_price' => 4_700_000,
            'gw_price' => 9_000,
            'is_active' => true,
        ]);

        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $umum->id,
            'volume_price' => 5_500_000,
            'gw_price' => 11_000,
        ]);
        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $shoes->id,
            'volume_price' => 4_700_000,
            'gw_price' => 9_000,
        ]);

        $marking->load('pricings.pricingItem');

        $row = MarkingPricingResolver::pickRow($marking->pricings, (string) $shoes->id);

        $this->assertNotNull($row);
        $this->assertSame((int) $shoes->id, (int) $row->pricing_item_id);
        $this->assertSame('4700000.00', (string) $row->volume_price);
    }

    public function test_normalize_key_strips_spaces_and_lowercases(): void
    {
        $this->assertSame('lartas', MarkingPricingResolver::normalizeKey('  Lartas  '));
        $this->assertSame('sepatunonbrand', MarkingPricingResolver::normalizeKey('Sepatu Non Brand'));
    }

    public function test_legacy_cbm_type_alias_maps_to_umum(): void
    {
        foreach (['cbm', 'piece', 'rim', 'ton', 'set'] as $legacy) {
            $this->assertSame('umum', MarkingPricingResolver::legacyCbmTypeAlias($legacy));
        }

        $this->assertSame('lartas', MarkingPricingResolver::legacyCbmTypeAlias('lartas'));
    }

    public function test_picks_row_by_legacy_cbm_alias(): void
    {
        $marking = Marking::query()->create([
            'code' => 'PICK-LEGACY',
            'customer_name' => 'Pick Customer',
        ]);

        $umum = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 5_500_000,
            'gw_price' => 11_000,
            'is_active' => true,
        ]);

        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $umum->id,
            'volume_price' => 5_500_000,
            'gw_price' => 11_000,
        ]);

        $marking->load('pricings.pricingItem');

        $row = MarkingPricingResolver::pickRow($marking->pricings, 'cbm');

        $this->assertNotNull($row);
        $this->assertSame((int) $umum->id, (int) $row->pricing_item_id);
    }

    public function test_does_not_fallback_to_umum_when_explicit_type_unknown(): void
    {
        $marking = Marking::query()->create([
            'code' => 'PICK-NO-FB',
            'customer_name' => 'Pick Customer',
        ]);

        $umum = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 1_000,
            'gw_price' => 500,
            'is_active' => true,
        ]);

        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $umum->id,
            'volume_price' => 1_000,
            'gw_price' => 500,
        ]);

        $marking->load('pricings.pricingItem');

        $row = MarkingPricingResolver::pickRow($marking->pricings, 'unknown item');

        $this->assertNull($row);
    }
}
