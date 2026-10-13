public function exportListProductsExcel(Request $request): BinaryFileResponse
    {
        abort_unless(auth()->user()->can('export barang'), 403);

        /** @var EloquentCollection<int, Barang> $barangRows */
        $barangRows = $this->barangListQuery($request)->get();

        return Excel::download(
            new BarangListProductsExport($barangRows),
            BarangListProductsExport::filename(),
        );
    }