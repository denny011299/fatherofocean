private function mapProductsForForm($products): array
    {
        return $products->map(fn (BarangProduct $p) => array_merge($p->toArray(), [
            'photo_url' => $p->photoUrl(),
        ]))->values()->all();
    }