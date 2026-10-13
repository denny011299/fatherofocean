private function shippingLabelPdfUrl(Barang $barang): ?string
    {
        if (! auth()->user()?->can('export barang')) {
            return null;
        }

        return route('barang.shipping-label.pdf', $barang);
    }