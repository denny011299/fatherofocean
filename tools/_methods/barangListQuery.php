private function barangListQuery(Request $request): Builder
    {
        $query = Barang::query()
            ->with([
                'invoiceItems',
                'gudangChina:id,name',
                'packing:id,name',
                'containers:id,partai',
                'products' => static fn ($q) => $q->orderBy('sort_order'),
            ])
            ->withCount('products');

        Marking::restrictBarangsQuery($query);
        $this->applyBarangListFilters($query, $request);

        return $query->orderBy('tanggal_masuk')->orderBy('id');
    }