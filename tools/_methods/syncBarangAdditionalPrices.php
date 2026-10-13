private function syncBarangAdditionalPrices(Request $request, Barang $barang): void
    {
        if (! $request->has('additional_prices')) {
            return;
        }

        $rows = $request->input('additional_prices', []);
        if (! is_array($rows)) {
            return;
        }

        $existing = $barang->additionalPrices()->get()->keyBy('id');
        $keepIds = [];
        $sort = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $price = (float) ($row['price'] ?? 0);

            if ($name === '' && $price <= 0) {
                continue;
            }

            $payload = [
                'sort_order' => $sort++,
                'name' => $name !== '' ? $name : '-',
                'price' => $price,
            ];

            $record = null;
            if (! empty($row['id'])) {
                $record = $existing->get((int) $row['id']);
            }

            if ($record) {
                if ($this->additionalPriceChanged($record, $payload)) {
                    $record->update($payload);
                }
                $keepIds[] = $record->id;
            } else {
                $created = $barang->additionalPrices()->create($payload);
                $keepIds[] = $created->id;
            }
        }

        $barang->additionalPrices()->whereNotIn('id', $keepIds)->delete();
    }