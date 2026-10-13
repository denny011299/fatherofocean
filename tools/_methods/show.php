public function show(Barang $barang): JsonResponse
    {
        $barang->load('customer', 'dimensions', 'products', 'trackingStatuses', 'packing');
        $data = $barang->toArray();
        $data['invoice_file_url'] = $barang->invoice_file
            ? Storage::disk('public')->url($barang->invoice_file)
            : null;
        $data['products'] = $barang->products->map(fn (BarangProduct $p) => array_merge($p->toArray(), [
            'photo_url' => $p->photo_path ? Storage::disk('public')->url($p->photo_path) : null,
        ]))->all();
        $data['products_export_url'] = $barang->products->isNotEmpty() && auth()->user()->can('export barang products')
            ? route('barang.products.export-excel', $barang)
            : null;

        return response()->json($data);
    }