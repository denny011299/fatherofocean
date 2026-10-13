private function barangItemsSummary(Barang $barang): string
    {
        if ($barang->relationLoaded('products') && $barang->products->isNotEmpty()) {
            $labels = $barang->products
                ->map(static function (BarangProduct $product): string {
                    $name = trim((string) ($product->product_name ?? ''));
                    if ($name !== '') {
                        return $name;
                    }

                    return trim((string) ($product->material ?? ''));
                })
                ->filter(static fn (string $label) => $label !== '')
                ->unique()
                ->values();

            if ($labels->isNotEmpty()) {
                return $labels->implode(', ');
            }
        }

        $form = trim((string) ($barang->form ?? ''));
        if ($form !== '') {
            return $form;
        }

        $supplier = trim((string) ($barang->supplier_chinese ?? ''));
        if ($supplier !== '') {
            return $supplier;
        }

        return '-';
    }