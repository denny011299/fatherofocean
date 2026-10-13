private function enforceProductLogisticsPermissions(Request $request, Barang $barang): void
    {
        if ($this->canEditBarangProductLogistics() || !$request->has('products')) {
            return;
        }

        $rows = $request->input('products', []);
        if (!is_array($rows)) {
            return;
        }

        $existingById = $barang->exists
            ? $barang->products()->get()->keyBy('id')
            : collect();

        $blocked = false;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (self::PRODUCT_LOGISTICS_FIELDS as $field) {
                if (!empty($row['id']) && $existingById->has((int) $row['id'])) {
                    /** @var BarangProduct $product */
                    $product = $existingById->get((int) $row['id']);
                    if ($this->productLogisticsValueChanged($row, $product, $field)) {
                        $blocked = true;
                    }
                    $rows[$index][$field] = $product->{$field};
                } elseif ($this->productLogisticsIncomingNotEmpty($row, $field)) {
                    $blocked = true;
                    $rows[$index][$field] = $field === 'ctns' ? 0 : 0;
                }
            }
        }

        $request->merge(['products' => $rows]);

        if ($blocked) {
            ActivityLog::record('Barang product logistics change blocked', $barang);
        }
    }