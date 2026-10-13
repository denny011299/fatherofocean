private function productLogisticsValueChanged(array $row, BarangProduct $product, string $field): bool
    {
        if ($field === 'ctns') {
            return (int) ($row[$field] ?? 0) !== (int) $product->ctns;
        }

        return abs((float) ($row[$field] ?? 0) - (float) $product->{$field}) > 0.0001;
    }