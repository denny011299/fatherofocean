<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\User;

/** Persentase komisi sales dari ongkos pengiriman (bukan asuransi/extra). */
final class SalesCommissionSettings
{
    public const KEY_DEFAULT = 'sales_commission_rate_default';

    public const FALLBACK_DEFAULT = 2.5;

    public static function defaultRate(): float
    {
        return self::parseRate(SystemSetting::getValue(self::KEY_DEFAULT), self::FALLBACK_DEFAULT);
    }

    public static function rateForSalesUser(?User $sales): float
    {
        if ($sales === null) {
            return self::defaultRate();
        }

        if ($sales->hasRole('sales')) {
            $override = $sales->commissionRateOverride();
            if ($override !== null) {
                return max(0, min(100, $override));
            }
        }

        return self::defaultRate();
    }

    public static function rateForSalesId(?int $salesId): float
    {
        if ($salesId === null || $salesId <= 0) {
            return self::defaultRate();
        }

        $sales = User::query()->find($salesId);

        return self::rateForSalesUser($sales);
    }

    /**
     * @return array{default_rate: float}
     */
    public static function current(): array
    {
        return [
            'default_rate' => self::defaultRate(),
        ];
    }

    /**
     * @return array{default_rate: float}
     */
    public static function update(float $defaultRate): array
    {
        SystemSetting::setValue(self::KEY_DEFAULT, (string) round($defaultRate, 2));

        return self::current();
    }

    private static function parseRate(?string $value, float $fallback): float
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return max(0, (float) $value);
    }
}
