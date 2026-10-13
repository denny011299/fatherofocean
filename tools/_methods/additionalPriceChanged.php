private function additionalPriceChanged(BarangAdditionalPrice $record, array $payload): bool
    {
        return (int) $record->sort_order !== (int) ($payload['sort_order'] ?? 0)
            || trim((string) $record->name) !== trim((string) ($payload['name'] ?? ''))
            || abs((float) $record->price - (float) ($payload['price'] ?? 0)) > 0.0001;
    }