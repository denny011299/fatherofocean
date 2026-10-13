private function parseNoResiInput(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn ($item) => trim((string) $item), $value),
                static fn (string $item) => $item !== '',
            ));
        }

        if (!is_string($value)) {
            return [];
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '[]') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return array_values(array_filter(
                array_map(static fn ($item) => trim((string) $item), $decoded),
                static fn (string $item) => $item !== '',
            ));
        }

        return [$trimmed];
    }