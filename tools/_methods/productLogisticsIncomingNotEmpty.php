private function productLogisticsIncomingNotEmpty(array $row, string $field): bool
    {
        if ($field === 'ctns') {
            return (int) ($row[$field] ?? 0) > 0;
        }

        return (float) ($row[$field] ?? 0) > 0;
    }