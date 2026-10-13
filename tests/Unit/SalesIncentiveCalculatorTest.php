<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CompanyRevenueItemModal;
use App\Models\MasterPricingItem;
use App\Models\SalesIncentiveTier;
use App\Support\SalesIncentiveCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SalesIncentiveCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function seedModalCategory(string $name = 'Umum'): MasterPricingItem
    {
        $item = MasterPricingItem::query()->create([
            'name' => $name,
            'volume_price' => 0,
            'gw_price' => 0,
            'is_active' => true,
        ]);

        CompanyRevenueItemModal::query()->create([
            'pricing_item_id' => $item->id,
            'cost_volume_price' => 575_000,
            'cost_gw_price' => 5_500,
        ]);

        return $item;
    }

    public function test_amount_for_cbm_uses_kelipatan_from_active_tier_per_category(): void
    {
        $item = $this->seedModalCategory();

        $tier = SalesIncentiveTier::query()->create([
            'name' => 'Per 20 CBM',
            'pricing_item_id' => $item->id,
            'cbm_per_block' => 20,
            'amount_per_block' => 500_000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $tier->syncPricingItemIds([$item->id]);

        $this->assertSame(500_000.0, SalesIncentiveCalculator::amountForCbm(20, $item->id));
        $this->assertSame(1_000_000.0, SalesIncentiveCalculator::amountForCbm(40, $item->id));
        $this->assertSame(500_000.0, SalesIncentiveCalculator::amountForCbm(35, $item->id));
        $this->assertSame(2, SalesIncentiveCalculator::fullBlocksForCbm(40, $item->id));
        $this->assertSame(0.0, SalesIncentiveCalculator::amountForCbm(40, null));
    }

    public function test_one_tier_can_cover_multiple_categories(): void
    {
        $umum = $this->seedModalCategory('Umum');
        $lcl = $this->seedModalCategory('LCL');

        $tier = SalesIncentiveTier::query()->create([
            'name' => 'Per 20 CBM',
            'pricing_item_id' => $umum->id,
            'cbm_per_block' => 20,
            'amount_per_block' => 500_000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $tier->syncPricingItemIds([$umum->id, $lcl->id]);

        $this->assertSame(500_000.0, SalesIncentiveCalculator::amountForCbm(20, $umum->id));
        $this->assertSame(500_000.0, SalesIncentiveCalculator::amountForCbm(20, $lcl->id));
        $this->assertSame(1_000_000.0, SalesIncentiveCalculator::totalAmountForCategoryGroups([
            (object) ['pricing_item_id' => $umum->id, 'cbm' => 20.0],
            (object) ['pricing_item_id' => $lcl->id, 'cbm' => 20.0],
        ]));
    }

    public function test_tier_matches_cbm_range_by_sort_order_within_category(): void
    {
        $item = $this->seedModalCategory('LCL');

        SalesIncentiveTier::query()->create([
            'name' => 'Kecil',
            'pricing_item_id' => $item->id,
            'cbm_min' => 0,
            'cbm_max' => 50,
            'cbm_per_block' => 20,
            'amount_per_block' => 500_000,
            'sort_order' => 1,
            'is_active' => true,
        ])->syncPricingItemIds([$item->id]);
        SalesIncentiveTier::query()->create([
            'name' => 'Besar',
            'pricing_item_id' => $item->id,
            'cbm_min' => 50,
            'cbm_max' => null,
            'cbm_per_block' => 20,
            'amount_per_block' => 600_000,
            'sort_order' => 2,
            'is_active' => true,
        ])->syncPricingItemIds([$item->id]);

        $this->assertSame(1_000_000.0, SalesIncentiveCalculator::amountForCbm(40, $item->id));
        $this->assertSame(1_800_000.0, SalesIncentiveCalculator::amountForCbm(60, $item->id));
    }

    public function test_different_categories_use_different_tiers(): void
    {
        $umum = $this->seedModalCategory('Umum');
        $lcl = $this->seedModalCategory('LCL');

        SalesIncentiveTier::query()->create([
            'name' => 'Umum 20',
            'pricing_item_id' => $umum->id,
            'cbm_per_block' => 20,
            'amount_per_block' => 500_000,
            'sort_order' => 1,
            'is_active' => true,
        ])->syncPricingItemIds([$umum->id]);
        SalesIncentiveTier::query()->create([
            'name' => 'LCL 20',
            'pricing_item_id' => $lcl->id,
            'cbm_per_block' => 20,
            'amount_per_block' => 700_000,
            'sort_order' => 1,
            'is_active' => true,
        ])->syncPricingItemIds([$lcl->id]);

        $this->assertSame(500_000.0, SalesIncentiveCalculator::amountForCbm(20, $umum->id));
        $this->assertSame(700_000.0, SalesIncentiveCalculator::amountForCbm(20, $lcl->id));
        $this->assertSame(1_200_000.0, SalesIncentiveCalculator::totalAmountForCategoryGroups([
            (object) ['pricing_item_id' => $umum->id, 'cbm' => 20.0],
            (object) ['pricing_item_id' => $lcl->id, 'cbm' => 20.0],
        ]));
    }
}
