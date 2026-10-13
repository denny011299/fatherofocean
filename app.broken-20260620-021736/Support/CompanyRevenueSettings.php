<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CompanyRevenueItemModal;
use App\Models\MasterPricingItem;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Keuntungan perusahaan (loyalty/kas admin) tetap per pengiriman — dipotong dari PIC, bukan dasar komisi sales. */
final class CompanyRevenueSettings
{
    public const KEY_FIXED_AMOUNT = 'company_revenue_fixed_amount';

    public const KEY_COST_VOLUME_PRICE = 'company_revenue_cost_volume_price';

    public const KEY_COST_GW_PRICE = 'company_revenue_cost_gw_price';

    public const FALLBACK_AMOUNT = 150_000.0;

    public static function fixedAmount(): float
    {
        $value = SystemSetting::getValue(self::KEY_FIXED_AMOUNT);

        if ($value === null || $value === '') {
            return self::FALLBACK_AMOUNT;
        }

        return max(0, (float) $value);
    }

    /** @return array{volume_price: float, gw_price: float} */
    public static function modalRates(): array
    {
        return [
            'volume_price' => max(0, (float) (SystemSetting::getValue(self::KEY_COST_VOLUME_PRICE) ?? 0)),
            'gw_price' => max(0, (float) (SystemSetting::getValue(self::KEY_COST_GW_PRICE) ?? 0)),
        ];
    }

    /** @return Collection<int, CompanyRevenueItemModal> */
    public static function itemModals(): Collection
    {
        return CompanyRevenueItemModal::query()
            ->with('pricingItem:id,name')
            ->orderBy('pricing_item_id')
            ->get();
    }

    /**
     * @return list<array{pricing_item_id: int, pricing_item_name: string, volume_price: float, gw_price: float}>
     */
    public static function itemModalRatesForForm(): array
    {
        return self::itemModals()
            ->map(static function (CompanyRevenueItemModal $row): array {
                return [
                    'pricing_item_id' => (int) $row->pricing_item_id,
                    'pricing_item_name' => (string) ($row->pricingItem?->name ?? ''),
                    'volume_price' => max(0, (float) $row->cost_volume_price),
                    'gw_price' => max(0, (float) $row->cost_gw_price),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array{volume_price: float, gw_price: float}> */
    public static function itemModalRatesMap(): array
    {
        $map = [];

        foreach (self::itemModals() as $row) {
            $map[(int) $row->pricing_item_id] = [
                'volume_price' => max(0, (float) $row->cost_volume_price),
                'gw_price' => max(0, (float) $row->cost_gw_price),
            ];
        }

        return $map;
    }

    /** @return array{volume_price: float, gw_price: float}|null */
    public static function modalRatesForPricingItem(?int $pricingItemId): ?array
    {
        if ($pricingItemId === null || $pricingItemId <= 0) {
            return null;
        }

        $row = CompanyRevenueItemModal::query()
            ->where('pricing_item_id', $pricingItemId)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'volume_price' => max(0, (float) $row->cost_volume_price),
            'gw_price' => max(0, (float) $row->cost_gw_price),
        ];
    }

    /** @return array{volume_price: float, gw_price: float}|null */
    public static function modalRatesForCbmType(mixed $cbmType): ?array
    {
        $pricingItemId = self::resolvePricingItemIdFromCbmType($cbmType);

        return $pricingItemId !== null
            ? self::modalRatesForPricingItem($pricingItemId)
            : null;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function modalPricingItemOptions(): array
    {
        return self::itemModals()
            ->map(static function (CompanyRevenueItemModal $row): array {
                return [
                    'id' => (int) $row->pricing_item_id,
                    'name' => (string) ($row->pricingItem?->name ?? ''),
                ];
            })
            ->filter(static fn (array $row): bool => $row['id'] > 0 && $row['name'] !== '')
            ->values()
            ->all();
    }

    /** @return list<int> */
    public static function modalPricingItemIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            self::modalPricingItemOptions(),
        );
    }

    public static function isModalPricingItemId(int $pricingItemId): bool
    {
        return in_array($pricingItemId, self::modalPricingItemIds(), true);
    }

    public static function resolvePricingItemIdFromCbmType(mixed $cbmType): ?int
    {
        return self::resolvePricingItemId($cbmType);
    }

    /** @return array{volume_price: float, gw_price: float} */
    public static function resolveModalRates(mixed $cbmType): array
    {
        $itemRates = self::modalRatesForCbmType($cbmType);
        if ($itemRates !== null) {
            return $itemRates;
        }

        return self::modalRates();
    }

    /**
     * @param  list<array{pricing_item_id: int|string, cost_volume_price?: float|int|string|null, cost_gw_price?: float|int|string|null}>  $rows
     * @return list<array{pricing_item_id: int, pricing_item_name: string, volume_price: float, gw_price: float}>
     */
    public static function syncItemModals(array $rows): array
    {
        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            $pricingItemId = (int) ($row['pricing_item_id'] ?? 0);
            if ($pricingItemId <= 0 || isset($seen[$pricingItemId])) {
                continue;
            }

            $seen[$pricingItemId] = true;
            $normalized[] = [
                'pricing_item_id' => $pricingItemId,
                'cost_volume_price' => round(max(0, (float) ($row['cost_volume_price'] ?? 0)), 2),
                'cost_gw_price' => round(max(0, (float) ($row['cost_gw_price'] ?? 0)), 2),
            ];
        }

        DB::transaction(static function () use ($normalized): void {
            $keepIds = [];

            foreach ($normalized as $row) {
                CompanyRevenueItemModal::query()->updateOrCreate(
                    ['pricing_item_id' => $row['pricing_item_id']],
                    [
                        'cost_volume_price' => $row['cost_volume_price'],
                        'cost_gw_price' => $row['cost_gw_price'],
                    ],
                );
                $keepIds[] = $row['pricing_item_id'];
            }

            if ($keepIds === []) {
                CompanyRevenueItemModal::query()->delete();

                return;
            }

            CompanyRevenueItemModal::query()
                ->whereNotIn('pricing_item_id', $keepIds)
                ->delete();
        });

        return self::itemModalRatesForForm();
    }

    public static function update(float $amount): float
    {
        $amount = round(max(0, $amount), 2);
        SystemSetting::setValue(self::KEY_FIXED_AMOUNT, (string) $amount);

        return $amount;
    }

    /** @return array{volume_price: float, gw_price: float} */
    public static function updateModalRates(float $volumePrice, float $gwPrice): array
    {
        $rates = [
            'volume_price' => round(max(0, $volumePrice), 2),
            'gw_price' => round(max(0, $gwPrice), 2),
        ];

        SystemSetting::setValue(self::KEY_COST_VOLUME_PRICE, (string) $rates['volume_price']);
        SystemSetting::setValue(self::KEY_COST_GW_PRICE, (string) $rates['gw_price']);

        return $rates;
    }

    private static function resolvePricingItemId(mixed $cbmType): ?int
    {
        $raw = trim((string) ($cbmType ?? ''));
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            $id = (int) $raw;

            return MasterPricingItem::query()->whereKey($id)->exists() ? $id : null;
        }

        $normalized = MarkingPricingResolver::normalizeKey($raw);
        $resolved = MarkingPricingResolver::legacyCbmTypeAlias($normalized);

        $item = MasterPricingItem::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$resolved])
            ->first();

        if ($item !== null) {
            return (int) $item->id;
        }

        $item = MasterPricingItem::query()
            ->get(['id', 'name'])
            ->first(static function (MasterPricingItem $candidate) use ($resolved): bool {
                $name = MarkingPricingResolver::normalizeKey($candidate->name);

                return $name !== ''
                    && (str_contains($name, $resolved) || str_contains($resolved, $name));
            });

        return $item !== null ? (int) $item->id : null;
    }
}
