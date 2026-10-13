<?php

declare(strict_types=1);

namespace App\Support;

final class CbmFormat
{
    public static function truncate(float $value): float
    {
        if ($value < 0) {
            return 0.0;
        }

        return round($value, 3, PHP_ROUND_HALF_UP);
    }
}
