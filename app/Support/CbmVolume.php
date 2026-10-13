<?php

declare(strict_types=1);

namespace App\Support;

/** CBM dari dimensi (P×L×T cm): dibagi {@see DIMENSION_DIVISOR}, lalu {@see CbmFormat::truncate()}. */
final class CbmVolume
{
    public const DIMENSION_DIVISOR = 1_000_000;

    public static function rawLine(float $length, float $width, float $height, int $ctns = 1): float
    {
        return max(0.0, $length)
            * max(0.0, $width)
            * max(0.0, $height)
            * max(0, $ctns)
            / self::DIMENSION_DIVISOR;
    }

    public static function line(float $length, float $width, float $height, int $ctns = 1): float
    {
        return CbmFormat::truncate(self::rawLine($length, $width, $height, $ctns));
    }

    public static function unit(float $length, float $width, float $height): float
    {
        return self::line($length, $width, $height, 1);
    }
}
