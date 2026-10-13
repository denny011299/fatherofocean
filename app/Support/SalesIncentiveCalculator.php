<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SalesIncentiveTier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Insentif sales per kategori harga modal (master_pricing_items / company_revenue_item_modals).
 *
 * Formula per kategori (agregat CBM bulan stuffing per sales + kategori):
 *   amount = floor(total_cbm ÷ cbm_per_block) × amount_per_block
 * Tier dipilih dari baris aktif yang mencakup pricing_item_id (pivot multi-kategori
 * atau legacy pricing_item_id), urut sort_order, dan total CBM masuk range
 * cbm_min..cbm_max (opsional). Satu aturan bisa jadi patokan banyak kategori.
 */
final class SalesIncentiveCalculator
{
    public static function schemaReady(): bool
    {
        return Schema::hasTable('sales_incentive_tiers');
    }

    public static function tierForCbm(float $cbm, ?int $pricingItemId = null): ?SalesIncentiveTier
    {
        if (! self::schemaReady() || $cbm <= 0 || $pricingItemId === null || $pricingItemId <= 0) {
            return null;
        }

        foreach (self::activeTiersForPricingItem($pricingItemId) as $tier) {
            $min = (float) ($tier->cbm_min ?? 0);
            $max = $tier->cbm_max !== null ? (float) $tier->cbm_max : null;

            if ($cbm < $min) {
                continue;
            }

            if ($max !== null && $cbm > $max) {
                continue;
            }

            return $tier;
        }

        return null;
    }

    public static function amountForCbm(float $cbm, ?int $pricingItemId = null): float
    {
        $tier = self::tierForCbm($cbm, $pricingItemId);
        if ($tier === null || $cbm <= 0) {
            return 0.0;
        }

        $block = (float) $tier->cbm_per_block;
        if ($block <= 0) {
            return 0.0;
        }

        $blocks = (int) floor($cbm / $block);

        return round($blocks * (float) $tier->amount_per_block, 2);
    }

    public static function fullBlocksForCbm(float $cbm, ?int $pricingItemId = null): int
    {
        $tier = self::tierForCbm($cbm, $pricingItemId);
        if ($tier === null || $cbm <= 0) {
            return 0;
        }

        $block = (float) $tier->cbm_per_block;
        if ($block <= 0) {
            return 0;
        }

        return (int) floor($cbm / $block);
    }

    /**
     * @param  Collection<int, object{pricing_item_id: ?int, cbm: float}>|iterable<int, object{pricing_item_id: ?int, cbm: float}>  $groups
     */
    public static function totalAmountForCategoryGroups(iterable $groups): float
    {
        $total = 0.0;

        foreach ($groups as $group) {
            $pricingItemId = isset($group->pricing_item_id) ? (int) $group->pricing_item_id : null;
            $cbm = (float) ($group->cbm ?? 0);
            $total += self::amountForCbm($cbm, $pricingItemId > 0 ? $pricingItemId : null);
        }

        return round($total, 2);
    }

    /**
     * @param  Collection<int, object{pricing_item_id: ?int, cbm: float}>|iterable<int, object{pricing_item_id: ?int, cbm: float}>  $groups
     */
    public static function totalFullBlocksForCategoryGroups(iterable $groups): int
    {
        $total = 0;

        foreach ($groups as $group) {
            $pricingItemId = isset($group->pricing_item_id) ? (int) $group->pricing_item_id : null;
            $cbm = (float) ($group->cbm ?? 0);
            $total += self::fullBlocksForCbm($cbm, $pricingItemId > 0 ? $pricingItemId : null);
        }

        return $total;
    }

    /** @return Collection<int, SalesIncentiveTier> */
    private static function activeTiersForPricingItem(int $pricingItemId): Collection
    {
        return SalesIncentiveTier::query()
            ->active()
            ->forPricingItem($pricingItemId)
            ->orderBy('sort_order')
            ->get();
    }
}
