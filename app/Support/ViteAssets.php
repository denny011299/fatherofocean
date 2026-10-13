<?php

declare(strict_types=1);

namespace App\Support;

final class ViteAssets
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    public static function useBuiltAssets(): bool
    {
        if (! is_file(public_path('build/manifest.json'))) {
            return false;
        }

        if (app()->environment('production', 'staging')) {
            return true;
        }

        return ! is_file(public_path('hot'));
    }

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        if (self::$manifest === null) {
            $path = public_path('build/manifest.json');
            self::$manifest = is_file($path)
                ? (json_decode((string) file_get_contents($path), true) ?: [])
                : [];
        }

        return self::$manifest;
    }

    public static function builtAssetUrl(string $entry): ?string
    {
        $file = self::manifest()[$entry]['file'] ?? null;

        return is_string($file) ? asset('build/'.$file) : null;
    }

    public static function removeStaleHotFile(): void
    {
        $hot = public_path('hot');

        if (! is_file($hot)) {
            return;
        }

        // Stale hot file from local `npm run dev` — never serve it when built assets exist.
        if (is_file(public_path('build/manifest.json'))) {
            @unlink($hot);

            return;
        }

        if (app()->environment('production', 'staging')) {
            @unlink($hot);
        }
    }
}
