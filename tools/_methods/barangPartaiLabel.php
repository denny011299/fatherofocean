private function barangPartaiLabel(Barang $barang): ?string
    {
        $codes = $barang->containers
            ->pluck('code')
            ->map(static fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isNotEmpty()) {
            return $codes->implode(', ');
        }

        return null;
    }