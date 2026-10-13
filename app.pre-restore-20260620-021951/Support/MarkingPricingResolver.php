<?php

declare(strict_types=1);

namespace App\Support;

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

    private static function normalizeMatchKey(string $value): string
    {
        return strtolower(preg_replace('/\s+/', '', trim($value)) ?? '');
    }
}
