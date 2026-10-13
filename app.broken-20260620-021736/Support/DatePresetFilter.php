<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;

final class DatePresetFilter
{
    /** @return list<string> */
    public static function presetValues(): array
    {
        return ['', 'today', 'yesterday', '7d', '30d', 'custom'];
    }

    public static function isValid(?string $preset): bool
    {
        return in_array($preset ?? '', self::presetValues(), true);
    }

    public static function normalizePreset(?string $preset): string
    {
        $preset = $preset ?? '';

        if ($preset === 'all' || $preset === 'none') {
            return '';
        }

        return self::isValid($preset) ? $preset : '';
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function resolveBounds(string $preset, ?string $from = null, ?string $to = null): array
    {
        $preset = self::normalizePreset($preset);

        if ($preset === '') {
            return [null, null];
        }

        $today = Carbon::today();

        if ($preset === 'custom') {
            return self::resolveCustomBounds($from, $to);
        }

        return match ($preset) {
            'today' => [$today->copy(), $today->copy()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            '7d' => [$today->copy()->subDays(6), $today->copy()],
            '30d' => [$today->copy()->subDays(29), $today->copy()],
            default => [null, null],
        };
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private static function resolveCustomBounds(?string $from, ?string $to): array
    {
        $from = trim((string) $from);
        $to = trim((string) $to);

        if ($from === '' && $to === '') {
            return [null, null];
        }

        $start = $from !== '' ? Carbon::parse($from)->startOfDay() : null;
        $end = $to !== '' ? Carbon::parse($to)->endOfDay() : null;

        if ($start !== null && $end !== null && $start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }
}
