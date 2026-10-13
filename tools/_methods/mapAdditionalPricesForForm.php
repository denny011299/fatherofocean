private function mapAdditionalPricesForForm($rows): array
    {
        return $rows->map(static fn (BarangAdditionalPrice $row): array => [
            'id' => $row->id,
            'name' => $row->name,
            'price' => $row->price,
        ])->values()->all();
    }