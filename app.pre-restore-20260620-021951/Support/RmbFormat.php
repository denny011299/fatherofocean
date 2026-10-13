<?php

declare(strict_types=1);

namespace App\Support;

final class RmbFormat
{
    public static function round(float $value): float
    {
        return round($value, 3);
    }

    public static function lineTotal(float $unitPrice, int $pcs, ?int $ctns = null): float
    {
        $multiplier = $ctns !== null ? max(0, $pcs) * max(0, $ctns) : max(0, $pcs);

        return self::round($unitPrice * $multiplier);
    }

    public static function format(float $value): string
    {
        return number_format($value, 3, '.', ',');
    }

    public static function parseInput(string $value): ?string
    {
        $clean = preg_replace('/[^\d.]/', '', $value) ?? '';

        return $clean === '' ? null : $clean;
    }
}
