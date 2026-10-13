<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CompanyRevenueItemModal;
use App\Models\CompanyRevenueSettingHistory;
use App\Models\MasterPricingItem;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Keuntungan perusahaan (loyalty/kas admin) tetap per pengiriman — dipotong dari PIC, bukan dasar komisi sales. */
final class CompanyRevenueSettings
{
    public const KEY_FIXED_AMOUNT = 'company_revenue_fixed_amount';

    public const KEY_COST_VOLUME_PRICE = 'company_revenue_cost_volume_price';

    public const KEY_COST_GW_PRICE = 'company_revenue_cost_gw_price';

    public const FALLBACK_AMOUNT = 150_000.0;

    public const EXPORT_COMPANY_NAME = 'Oceanlink';

    public static function exportCompanyName(): string
    {
        return self::EXPORT_COMPANY_NAME;
    }

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
        return self::zeroModalRates();
    }

    /** @return Collection<int, CompanyRevenueItemModal> */
    public static function itemModals(): Collection
    {
        if (! self::hasItemModalsTable()) {
            return collect();
        }

        return CompanyRevenueItemModal::query()
            ->with('pricingItem:id,name')
            ->orderBy('pricing_item_id')
            ->get();
    }

    /**
     * @return list<array{pricing_item_id: int, pricing_item_name: string, volume_price: float, gw_price: float, company_profit_per_shipment: float}>
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
                    'company_profit_per_shipment' => self::readProfitPerShipmentFromRow($row),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Referensi tarif untuk export omset (sheet Tarif Kategori).
     *
     * @return list<array{
     *     kategori: string,
     *     modal_cbm: float,
     *     modal_kg: float,
     *     profit_per_shipment: float,
     *     profit_is_fallback: bool,
     * }>
     */
    public static function exportCategoryReferenceRows(): array
    {
        $fallback = self::fixedAmount();

        return array_map(
            static function (array $row) use ($fallback): array {
                $configured = max(0, (float) ($row['company_profit_per_shipment'] ?? 0));
                $profit = $configured > 0 ? $configured : $fallback;

                return [
                    'kategori' => (string) ($row['pricing_item_name'] ?? ''),
                    'modal_cbm' => max(0, (float) ($row['volume_price'] ?? 0)),
                    'modal_kg' => max(0, (float) ($row['gw_price'] ?? 0)),
                    'profit_per_shipment' => $profit,
                    'profit_is_fallback' => $configured <= 0,
                ];
            },
            self::itemModalRatesForForm(),
        );
    }

    /** @return array<int, array{volume_price: float, gw_price: float, company_profit_per_shipment: float}> */
    public static function itemModalRatesMap(): array
    {
        $map = [];

        foreach (self::itemModals() as $row) {
            $map[(int) $row->pricing_item_id] = [
                'volume_price' => max(0, (float) $row->cost_volume_price),
                'gw_price' => max(0, (float) $row->cost_gw_price),
                'company_profit_per_shipment' => self::readProfitPerShipmentFromRow($row),
            ];
        }

        return $map;
    }

    public static function profitPerShipmentForPricingItem(?int $pricingItemId): float
    {
        if ($pricingItemId === null || $pricingItemId <= 0 || ! self::hasItemModalsTable()) {
            return self::fixedAmount();
        }

        $row = CompanyRevenueItemModal::query()
            ->where('pricing_item_id', $pricingItemId)
            ->first();

        if ($row === null) {
            return self::fixedAmount();
        }

        $configured = self::readProfitPerShipmentFromRow($row);

        return $configured > 0 ? $configured : self::fixedAmount();
    }

    public static function profitPerShipmentForCbmType(mixed $cbmType): float
    {
        $pricingItemId = self::resolvePricingItemIdFromCbmType($cbmType);

        return self::profitPerShipmentForPricingItem($pricingItemId);
    }

    public static function profitPerShipmentForKategoriLabel(string $kategoriLabel): float
    {
        $label = trim($kategoriLabel);
        if ($label === '' || $label === '-') {
            return self::fixedAmount();
        }

        $normalized = MarkingPricingResolver::normalizeKey($label);

        foreach (self::itemModals() as $row) {
            $name = trim((string) ($row->pricingItem?->name ?? ''));
            if ($name === '') {
                continue;
            }

            if (strcasecmp($name, $label) === 0) {
                return self::profitPerShipmentForPricingItem((int) $row->pricing_item_id);
            }

            $nameKey = MarkingPricingResolver::normalizeKey($name);
            if ($nameKey !== '' && ($nameKey === $normalized || str_contains($nameKey, $normalized) || str_contains($normalized, $nameKey))) {
                return self::profitPerShipmentForPricingItem((int) $row->pricing_item_id);
            }
        }

        return self::fixedAmount();
    }

    /** @return array{volume_price: float, gw_price: float}|null */
    public static function modalRatesForPricingItem(?int $pricingItemId): ?array
    {
        if ($pricingItemId === null || $pricingItemId <= 0 || ! self::hasItemModalsTable()) {
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

        return self::zeroModalRates();
    }

    /**
     * @param  list<array{pricing_item_id: int|string, cost_volume_price?: float|int|string|null, cost_gw_price?: float|int|string|null, company_profit_per_shipment?: float|int|string|null}>  $rows
     * @return list<array{pricing_item_id: int, pricing_item_name: string, volume_price: float, gw_price: float, company_profit_per_shipment: float}>
     */
    public static function syncItemModals(array $rows): array
    {
        if (! self::hasItemModalsTable()) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            $pricingItemId = (int) ($row['pricing_item_id'] ?? 0);
            if ($pricingItemId <= 0 || isset($seen[$pricingItemId])) {
                continue;
            }

            $seen[$pricingItemId] = true;
            $payload = [
                'pricing_item_id' => $pricingItemId,
                'cost_volume_price' => round(max(0, (float) ($row['cost_volume_price'] ?? 0)), 2),
                'cost_gw_price' => round(max(0, (float) ($row['cost_gw_price'] ?? 0)), 2),
            ];

            if (self::hasProfitPerShipmentColumn()) {
                $payload['company_profit_per_shipment'] = round(max(0, (float) ($row['company_profit_per_shipment'] ?? 0)), 2);
            }

            $normalized[] = $payload;
        }

        DB::transaction(static function () use ($normalized): void {
            $keepIds = [];

            foreach ($normalized as $row) {
                $attributes = [
                    'cost_volume_price' => $row['cost_volume_price'],
                    'cost_gw_price' => $row['cost_gw_price'],
                ];

                if (isset($row['company_profit_per_shipment'])) {
                    $attributes['company_profit_per_shipment'] = $row['company_profit_per_shipment'];
                }

                CompanyRevenueItemModal::query()->updateOrCreate(
                    ['pricing_item_id' => $row['pricing_item_id']],
                    $attributes,
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

        $previousRaw = SystemSetting::getValue(self::KEY_FIXED_AMOUNT);
        $previous = ($previousRaw === null || $previousRaw === '')
            ? null
            : round(max(0, (float) $previousRaw), 2);

        SystemSetting::setValue(self::KEY_FIXED_AMOUNT, (string) $amount);

        CompanyRevenueSettingHistory::query()->create([
            'setting_key' => self::KEY_FIXED_AMOUNT,
            'old_value' => $previous,
            'new_value' => $amount,
            'changed_by_user_id' => auth()->id(),
            'changed_at' => now(),
            'notes' => 'Updated from system general settings',
        ]);

        return $amount;
    }

    /** @return array{volume_price: float, gw_price: float} */
    private static function zeroModalRates(): array
    {
        return [
            'volume_price' => 0.0,
            'gw_price' => 0.0,
        ];
    }

    private static function hasItemModalsTable(): bool
    {
        return Schema::hasTable('company_revenue_item_modals');
    }

    private static function hasProfitPerShipmentColumn(): bool
    {
        if (! self::hasItemModalsTable()) {
            return false;
        }

        return Schema::hasColumn('company_revenue_item_modals', 'company_profit_per_shipment')
            || Schema::hasColumn('company_revenue_item_modals', 'company_profit_per_cbm');
    }

    private static function readProfitPerShipmentFromRow(CompanyRevenueItemModal $row): float
    {
        if (! self::hasProfitPerShipmentColumn()) {
            return 0.0;
        }

        if (Schema::hasColumn('company_revenue_item_modals', 'company_profit_per_shipment')) {
            return self::normalizeProfitPerShipment((float) $row->company_profit_per_shipment);
        }

        return self::normalizeProfitPerShipment((float) ($row->company_profit_per_cbm ?? 0));
    }

    private static function normalizeProfitPerShipment(float $value): float
    {
        return round(max(0, $value), 2);
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
