<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Container;
use Illuminate\Support\Collection;

final class PartaiFilterOptions
{
    /** @return Collection<int, string> */
    public static function all(): Collection
    {
        return Container::query()
            ->whereNotNull('partai')
            ->where('partai', '!=', '')
            ->distinct()
            ->orderBy('partai')
            ->pluck('partai')
            ->map(static fn (mixed $partai): string => trim((string) $partai))
            ->filter(static fn (string $partai): bool => $partai !== '')
            ->values();
    }
}
