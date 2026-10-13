<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Marking;
use App\Models\MarkingPricing;
use App\Models\MasterPricingItem;
use App\Support\MarkingBarangPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarkingBarangPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_picks_pricing_row_by_cbm_type_matching_item_name(): void
    {
        $marking = Marking::query()->create([
            'code' => 'GW-TEST',
            'customer_name' => 'GW Customer',
        ]);
        $umum = MasterPricingItem::query()->create([
            'name' => 'Umum',
            'volume_price' => 1000,
            'gw_price' => 5000,
            'is_active' => true,
        ]);
        $lartas = MasterPricingItem::query()->create([
            'name' => 'Lartas',
            'volume_price' => 2000,
            'gw_price' => 9000,
            'is_active' => true,
        ]);

        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $umum->id,
            'volume_price' => 1000,
            'gw_price' => 5000,
        ]);
        MarkingPricing::query()->create([
            'marking_id' => $marking->id,
            'pricing_item_id' => $lartas->id,
            'volume_price' => 2000,
            'gw_price' => 9000,
        ]);

        $row = MarkingBarangPricing::pickPricingRow($marking->fresh(), 'lartas');

        $this->assertNotNull($row);
        $this->assertSame('2000.00', (string) $row->volume_price);
        $this->assertSame('9000.00', (string) $row->gw_price);
    }
}
