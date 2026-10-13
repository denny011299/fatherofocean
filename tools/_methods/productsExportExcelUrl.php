private function productsExportExcelUrl(Barang $barang): ?string
    {
        if (! auth()->user()?->can('export barang')) {
            return null;
        }

        return route('barang.products.export-excel', $barang);
    }