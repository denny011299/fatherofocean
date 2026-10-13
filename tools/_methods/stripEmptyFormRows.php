private function stripEmptyFormRows(Request $request): void
    {
        if ($request->has('products') && is_array($request->input('products'))) {
            $filtered = [];
            foreach ($request->input('products') as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (Barang::isEmptyProductInputRow($row) && ! $request->hasFile("products.{$index}.photo")) {
                    continue;
                }
                $filtered[] = $row;
            }
            $request->merge(['products' => array_values($filtered)]);
        }

        if ($request->has('additional_prices') && is_array($request->input('additional_prices'))) {
            $filtered = [];
            foreach ($request->input('additional_prices') as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $price = (float) ($row['price'] ?? 0);
                if ($name === '' && $price <= 0 && empty($row['id'])) {
                    continue;
                }
                $filtered[] = $row;
            }
            $request->merge(['additional_prices' => array_values($filtered)]);
        }
    }