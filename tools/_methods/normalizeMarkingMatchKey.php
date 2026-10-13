private function normalizeMarkingMatchKey(mixed $value): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')) ?? '');

        return mb_strtolower($normalized);
    }