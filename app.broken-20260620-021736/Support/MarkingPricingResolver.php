<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MarkingPricing;
use Illuminate\Support\Collection;

final class MarkingPricingResolver
{
    /**
     * @return array{volume_price: float|int|string, gw_price: float|int|string}
     */
    public static function modalRatesFromPricingRow(?MarkingPricing $row): array
    {
        if ($row === null) {
            return ['volume_price' => 0, 'gw_price' => 0];
        }

        return [
            'volume_price' => $row->volume_price ?? 0,
            'gw_price' => $row->gw_price ?? 0,
        ];
    }

    public static function normalizeKey(string $value): string
    {
        return self::normalizeMatchKey($value);
    }

    public static function legacyCbmTypeAlias(string $raw): string
    {
        $key = self::normalizeMatchKey($raw);

        return self::LEGACY_CBM_TYPE_ALIASES[$key] ?? $key;
    }

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

    private static function resolveCbmTypeKey(string $raw): string
    {
        return self::legacyCbmTypeAlias($raw);
    }

    private static function normalizeMatchKey(string $value): string
    {
        return strtolower(preg_replace('/\s+/', '', trim($value)) ?? '');
    }
}
