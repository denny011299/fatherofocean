<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Barang;
use App\Models\Marking;
use App\Models\MarkingPricing;
use Illuminate\Support\Collection;

final class MarkingPricingResolver
{
    /** @var array<string, string> */
    private const LEGACY_CBM_TYPE_ALIASES = [
        'cbm' => 'umum',
        'piece' => 'umum',
        'rim' => 'umum',
        'ton' => 'umum',
        'set' => 'umum',
    ];

    /**
     * @param  Collection<int, MarkingPricing>  $pricings
     */
    public static function pickRow(Collection $pricings, string $cbmType): ?MarkingPricing
    {
        $rawType = trim($cbmType);
        if ($rawType === '') {
            return null;
        }

        if (ctype_digit($rawType)) {
            $byId = $pricings->first(
                static fn (MarkingPricing $row): bool => (int) $row->pricing_item_id === (int) $rawType,
            );
            if ($byId !== null) {
                return $byId;
            }
        }

        $resolvedType = self::resolveCbmTypeKey($rawType);

        $exact = $pricings->first(function (MarkingPricing $row) use ($resolvedType): bool {
            $name = self::normalizeMatchKey($row->pricingItem?->name ?? '');

            return $name !== '' && $name === $resolvedType;
        });
        if ($exact !== null) {
            return $exact;
        }

        return $pricings->first(function (MarkingPricing $row) use ($resolvedType): bool {
            $name = self::normalizeMatchKey($row->pricingItem?->name ?? '');
            if ($name === '') {
                return false;
            }

            return str_contains($name, $resolvedType) || str_contains($resolvedType, $name);
        });
    }

    /**
     * Normalize cbm_type / pricing item name for comparison.
     * Mirrors SQL: LOWER(REPLACE(TRIM(value), ' ', '')).
     */
    public static function normalizeKey(string $value): string
    {
        return self::normalizeMatchKey($value);
    }

    /**
     * Map legacy cbm_type values (cbm, piece, rim, ton, set) to "umum".
     * Expects an already-normalized key from normalizeKey().
     */
    public static function legacyCbmTypeAlias(string $key): string
    {
        return self::LEGACY_CBM_TYPE_ALIASES[$key] ?? $key;
    }

    private static function resolveCbmTypeKey(string $raw): string
    {
        return self::legacyCbmTypeAlias(self::normalizeKey($raw));
    }

    /**
     * Modal/cost rates from a marking pricing row (Barang cost auto-fill).
     *
     * @return array{volume_price: float, gw_price: float}
     */
    public static function modalRatesFromPricingRow(?MarkingPricing $row): array
    {
        if ($row === null) {
            return [
                'volume_price' => 0.0,
                'gw_price' => 0.0,
            ];
        }

        return [
            'volume_price' => max(0, (float) ($row->volume_price ?? 0)),
            'gw_price' => max(0, (float) ($row->gw_price ?? 0)),
        ];
    }

    public static function forBarang(Barang $barang): ?MarkingPricing
    {
        $markingValue = trim((string) ($barang->marking ?? ''));
        if ($markingValue === '') {
            return null;
        }

        $marking = Marking::query()->whereBarangMarkingValue($markingValue)->first();
        if ($marking === null) {
            return null;
        }

        $marking->loadMissing('pricings.pricingItem');

        $picked = self::pickRow($marking->pricings, (string) ($barang->cbm_type ?? ''));
        if ($picked !== null) {
            return $picked;
        }

        // Fallback: kategori Umum, lalu baris pricing pertama yang punya harga.
        return self::fallbackPricingRow($marking->pricings);
    }

    /**
     * @param  Collection<int, MarkingPricing>  $pricings
     */
    public static function fallbackPricingRow(Collection $pricings): ?MarkingPricing
    {
        if ($pricings->isEmpty()) {
            return null;
        }

        $umum = $pricings->first(function (MarkingPricing $row): bool {
            $name = self::normalizeMatchKey($row->pricingItem?->name ?? '');

            return $name === 'umum';
        });
        if ($umum !== null) {
            return $umum;
        }

        return $pricings->first(static function (MarkingPricing $row): bool {
            return max(0, (float) ($row->volume_price ?? 0)) > 0
                || max(0, (float) ($row->gw_price ?? 0)) > 0;
        }) ?? $pricings->first();
    }

    private static function normalizeMatchKey(string $value): string
    {
        return strtolower(preg_replace('/\s+/', '', trim($value)) ?? '');
    }
}
