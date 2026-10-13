private function barangFormPayload(Barang $barang): array
    {
        $barang->loadMissing('customer', 'dimensions', 'products');

        return [
            'id' => $barang->id,
            'user_id' => $barang->user_id,
            'gudang_china_id' => $barang->gudang_china_id,
            'no_index' => $barang->no_index,
            'marking' => $barang->marking,
            'supplier_chinese' => $barang->supplier_chinese,
            'customer_name' => $barang->customer_name,
            'ctns' => $barang->ctns,
            'price' => $barang->price,
            'cbm_type' => $barang->cbm_type,
            'rate' => $barang->rate,
            'weight' => $barang->weight,
            'price_per_cbm' => $barang->price_per_cbm,
            'weight_price' => $barang->weight_price,
            'weight_threshold_kg' => $barang->weight_threshold_kg,
            'packing_id' => $barang->packing_id,
            'other' => $barang->other,
            'notes' => $barang->notes,
            'no_resi' => $barang->no_resi ?? [],
            'tanggal_masuk' => $barang->tanggal_masuk?->toDateString(),
            'invoice_file' => $barang->invoice_file,
            'invoice_file_url' => $barang->invoice_file
                ? Storage::disk('public')->url($barang->invoice_file)
                : null,
            'products' => $barang->products->map(fn (BarangProduct $p) => array_merge($p->toArray(), [
                'photo_url' => $p->photo_path ? Storage::disk('public')->url($p->photo_path) : null,
            ]))->values()->all(),
            'dimensions' => $barang->dimensions->toArray(),
            'products_export_url' => $barang->products->isNotEmpty() && auth()->user()->can('export barang products')
                ? route('barang.products.export-excel', $barang)
                : null,
        ];
    }