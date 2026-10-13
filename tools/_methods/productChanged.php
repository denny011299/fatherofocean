private function productChanged(BarangProduct $product, array $payload): bool
    {
        if (array_key_exists('photo_path', $payload)) {
            return true;
        }

        $stringFields = ['product_name', 'material'];
        foreach ($stringFields as $field) {
            if (trim((string) $product->{$field}) !== trim((string) ($payload[$field] ?? ''))) {
                return true;
            }
        }

        $intFields = ['sort_order', 'ctns', 'pcs_per_box'];
        foreach ($intFields as $field) {
            if ((int) $product->{$field} !== (int) ($payload[$field] ?? 0)) {
                return true;
            }
        }

        $decimalFields = ['gross_weight_kg', 'length_cm', 'width_cm', 'height_cm', 'price_per_unit_rmb', 'total_price_rmb'];
        foreach ($decimalFields as $field) {
            if (abs((float) $product->{$field} - (float) ($payload[$field] ?? 0)) > 0.0001) {
                return true;
            }
        }

        return false;
    }