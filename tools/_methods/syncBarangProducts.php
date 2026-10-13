private function syncBarangProducts(Request $request, Barang $barang): void
    {
        if (!$request->has('products')) {
            return;
        }

        $rows = $request->input('products', []);
        if (!is_array($rows)) {
            return;
        }

        $existing = $barang->products()->get()->keyBy('id');
        $keepIds = [];
        $sort = 0;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($this->isEmptyProductRow($row) && ! $request->hasFile("products.{$index}.photo")) {
                continue;
            }

            $product = null;
            if (!empty($row['id'])) {
                $product = $existing->get((int) $row['id']);
            }

            $payload = [
                'sort_order' => $sort++,
                'ctns' => (int) ($row['ctns'] ?? 0),
                'product_name' => $row['product_name'] ?? null,
                'material' => $row['material'] ?? null,
                'pcs_per_box' => (int) ($row['pcs_per_box'] ?? 0),
                'gross_weight_kg' => $row['gross_weight_kg'] ?? 0,
                'length_cm' => $row['length_cm'] ?? 0,
                'width_cm' => $row['width_cm'] ?? 0,
                'height_cm' => $row['height_cm'] ?? 0,
                'price_per_unit_rmb' => $row['price_per_unit_rmb'] ?? 0,
                'total_price_rmb' => $row['total_price_rmb'] ?? 0,
            ];

            $file = $request->file("products.{$index}.photo");
            if ($file) {
                if ($product?->photo_path) {
                    Storage::disk('public')->delete($product->photo_path);
                }
                $payload['photo_path'] = UploadedImageCompressor::store($file, 'barang-products', 'public');
            } elseif ($request->boolean("products.{$index}.remove_photo") && $product?->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
                $payload['photo_path'] = null;
            }

            if ($product) {
                if ($this->productChanged($product, $payload)) {
                    $product->update($payload);
                }
                $keepIds[] = $product->id;
            } else {
                $created = $barang->products()->create($payload);
                $keepIds[] = $created->id;
            }
        }

        $removed = $barang->products()->whereNotIn('id', $keepIds)->get();
        foreach ($removed as $product) {
            $product->delete();
        }
    }