<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Http\Request;

final class DailyRate
{
    public const KEY_RATE = 'daily_rate';

    public const KEY_EFFECTIVE_DATE = 'daily_rate_effective_date';

    /**
     * @return array{rate: ?float, effective_date: ?string}
     */
    public static function current(): array
    {
        return [
            'rate' => self::rate(),
            'effective_date' => self::effectiveDate(),
        ];
    }

    public static function rate(): ?float
    {
        $value = SystemSetting::getValue(self::KEY_RATE);

        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    public static function effectiveDate(): ?string
    {
        $value = SystemSetting::getValue(self::KEY_EFFECTIVE_DATE);

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * @return array{rate: float, effective_date: string}
     */
    public static function update(float $rate, ?string $effectiveDate = null): array
    {
        $effectiveDate ??= now()->toDateString();

        SystemSetting::setValue(self::KEY_RATE, (string) $rate);
        SystemSetting::setValue(self::KEY_EFFECTIVE_DATE, $effectiveDate);

        return [
            'rate' => $rate,
            'effective_date' => $effectiveDate,
        ];
    }

    /**
     * @param  bool  $preserveExisting  When true (update), keep submitted rate from the record.
     */
    public static function applyToRequest(Request $request, bool $preserveExisting = false): void
    {
        if ($preserveExisting) {
            $submitted = $request->input('rate');
            if ($submitted !== null && $submitted !== '') {
                return;
            }
        }

        $rate = self::rate();

        if ($rate === null) {
            return;
        }

        $request->merge(['rate' => (string) $rate]);
    }
}
