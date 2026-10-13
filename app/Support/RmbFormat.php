<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RMB (Chinese Yuan) precision: max 3 decimal places.
 *
 * Used for barang purchase price and product unit/line totals from China warehouse context.
 */
final class RmbFormat
{
    public const DECIMALS = 3;

    public static function round(float $value): float
    {
        return round(max(0.0, $value), self::DECIMALS, PHP_ROUND_HALF_UP);
    }

    public static function multiply(float $unitPrice, int $qty): float
    {
        if ($unitPrice <= 0 || $qty <= 0) {
            return 0.0;
        }

        return self::round($unitPrice * $qty);
    }

    public static function lineTotal(float $unitPrice, int $pcsPerBox, int $ctns = 0): float
    {
        if ($unitPrice <= 0 || $pcsPerBox <= 0) {
            return 0.0;
        }

        $qty = $ctns > 0 ? $pcsPerBox * $ctns : $pcsPerBox;

        return self::multiply($unitPrice, $qty);
    }

    public static function sum(float ...$values): float
    {
        return self::round(array_sum($values));
    }

    /**
     * Parse masked/form RMB input: comma = thousands separator, dot = decimal.
     */
    public static function parseInput(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9.,\-]/', '', $value) ?? '';
        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        $normalized = str_replace(',', '', $normalized);

        return $normalized;
    }

    public static function format(float $value, string $decimal = '.', string $thousands = ','): string
    {
        $rounded = self::round($value);
        $formatted = number_format($rounded, self::DECIMALS, $decimal, $thousands);
        if (! str_contains($formatted, $decimal)) {
            return $formatted;
        }

        $formatted = rtrim(rtrim($formatted, '0'), $decimal);

        return $formatted === '' ? '0' : $formatted;
    }

    public static function formatWithSymbol(float $value): string
    {
        return '¥'.self::format($value);
    }
}
