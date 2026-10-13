private function isEmptyProductRow(array $row): bool
    {
        $textFields = ['product_name', 'material'];
        foreach ($textFields as $field) {
            if (!empty(trim((string) ($row[$field] ?? '')))) {
                return false;
            }
        }

        $numericFields = [
            'ctns', 'pcs_per_box', 'gross_weight_kg', 'net_weight_kg',
            'length_cm', 'width_cm', 'height_cm', 'price_per_unit_rmb', 'total_price_rmb',
        ];

        foreach ($numericFields as $field) {
            if ((float) ($row[$field] ?? 0) > 0) {
                return false;
            }
        }

        return empty($row['id']);
    }