private function originalPackingListDownloadUrl(Barang $barang): ?string
    {
        if (! $barang->invoice_file || ! Storage::disk('public')->exists($barang->invoice_file)) {
            return null;
        }

        return route('barang.original-pl.download', $barang);
    }