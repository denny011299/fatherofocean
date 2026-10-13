private function markingFilterValues(Request $request): array
    {
        $raw = $request->input('marking');
        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? $raw : [$raw];

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }