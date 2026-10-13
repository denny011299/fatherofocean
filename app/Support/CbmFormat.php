<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CBM billable/display precision: 3 decimal places.
 *
 * Digit ke-4 (0–5 turun, 6–9 naik) — bukan half-up standar (angka 5 tidak naik).
 * DB columns may store more digits (e.g. decimal(12,6)); always normalize on write/display.
 */
final class CbmFormat
{
    public const DECIMALS = 3;

    /**
     * Normalisasi ke {@see DECIMALS} desimal: digit ke-4: 0–5 turun, 6–9 naik.
     */
    public static function truncate(float $value): float
    {
        $value = max(0.0, $value);

        $scaled4 = (int) floor($value * 10000 + 1e-9);
        $fourthDigit = $scaled4 % 10;
        $thousandths = intdiv($scaled4, 10);

        if ($fourthDigit >= 6) {
            $thousandths++;
        }

        return $thousandths / 1000;
    }

    /**
     * Normalize after summing values (prefer already-truncated per-line CBM so totals match UI).
     */
    public static function sum(float ...$values): float
    {
        return self::truncate(array_sum($values));
    }

    /** @deprecated Use {@see truncate()}. Kept for existing call sites. */
    public static function round(float $value): float
    {
        return self::truncate($value);
    }

    public static function format(float $value, string $decimal = '.', string $thousands = ''): string
    {
        return number_format(self::truncate($value), self::DECIMALS, $decimal, $thousands);
    }

    /** Tampilkan aktual → billable saat min charge invoice (TOTAL, mis. "0,027 -> 0,100"). */
    public static function formatWithMinCharge(float $actualCbm, float $billableCbm): string
    {
        return self::format($actualCbm, ',', '').' -> '.self::format($billableCbm, ',', '');
    }
}
