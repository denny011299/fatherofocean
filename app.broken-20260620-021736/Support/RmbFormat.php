<?php

declare(strict_types=1);

namespace App\Support;

/** RMB monetary precision: up to 3 decimal places. */
final class RmbFormat
{
    public const DECIMALS = 3;

    public static function round(float $value): float
    {
        return round(max(0.0, $value), self::DECIMALS);
    }

    public static function lineTotal(float $unitPrice, int $quantity): float
    {
        if ($unitPrice <= 0 || $quantity <= 0) {
            return 0.0;
        }

        return self::round($unitPrice * $quantity);
    }

    public static function format(float $value, string $decimal = '.', string $thousands = ','): string
    {
        return number_format(self::round($value), self::DECIMALS, $decimal, $thousands);
    }
}
