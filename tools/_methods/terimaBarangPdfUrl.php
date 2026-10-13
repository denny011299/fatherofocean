private function terimaBarangPdfUrl(Barang $barang): ?string
    {
        if (! auth()->user()?->can('export barang')) {
            return null;
        }

        return route('barang.terima-barang.pdf', $barang);
    }