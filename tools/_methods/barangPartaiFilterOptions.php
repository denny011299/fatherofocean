private function barangPartaiFilterOptions(): Collection
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