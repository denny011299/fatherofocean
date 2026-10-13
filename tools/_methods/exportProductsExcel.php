public function exportProductsExcel(Barang $barang): BinaryFileResponse
    {
        abort_unless(auth()->user()->can('export barang products'), 403);

        if (!$barang->products()->exists()) {
            abort(404);
        }

        $filename = 'barang-products-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $barang->no_index ?? (string) $barang->id) . '.xlsx';

        return Excel::download(new BarangProductsExport($barang), $filename);
    }