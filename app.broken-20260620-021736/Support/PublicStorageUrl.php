<?php

declare(strict_types=1);

namespace App\Support;

final class PublicStorageUrl
{
    public static function normalizePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', ltrim($path, '/'));
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = substr($normalized, strlen('storage/'));
        }

        return $normalized !== '' ? $normalized : null;
    }

    public static function url(?string $path): ?string
    {
        $normalized = self::normalizePath($path);
        if ($normalized === null) {
            return null;
        }

        return asset('storage/' . $normalized);
    }
}
