<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Business date for tracking timeline entries (admin / sales / customer).
 */
final class TrackingEventDate
{
    public static function containerColumnReady(): bool
    {
        return Schema::hasTable('container_tracking_statuses')
            && Schema::hasColumn('container_tracking_statuses', 'event_date');
    }

    public static function barangColumnReady(): bool
    {
        return Schema::hasTable('tracking_statuses')
            && Schema::hasColumn('tracking_statuses', 'event_date');
    }

    public static function normalize(null|string|CarbonInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function resolve(null|string|CarbonInterface $value): string
    {
        return self::normalize($value) ?? now()->toDateString();
    }

    /**
     * Prefer event_date; fall back to created_at for legacy rows.
     */
    public static function displayAt(mixed $eventDate, mixed $createdAt): ?Carbon
    {
        $normalized = self::normalize($eventDate instanceof CarbonInterface ? $eventDate : (is_string($eventDate) ? $eventDate : null));
        if ($normalized !== null) {
            return Carbon::parse($normalized)->startOfDay();
        }

        if ($createdAt instanceof CarbonInterface) {
            return Carbon::instance($createdAt)->copy();
        }

        if (is_string($createdAt) && trim($createdAt) !== '') {
            try {
                return Carbon::parse($createdAt);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public static function displayIso(mixed $eventDate, mixed $createdAt): ?string
    {
        return self::displayAt($eventDate, $createdAt)?->toIso8601String();
    }

    public static function displayLabel(mixed $eventDate, mixed $createdAt, string $format = 'd M Y'): string
    {
        $at = self::displayAt($eventDate, $createdAt);

        return $at !== null ? $at->format($format) : '-';
    }
}
