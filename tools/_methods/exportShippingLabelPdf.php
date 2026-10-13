public function exportShippingLabelPdf(Barang $barang): Response
    {
        abort_unless(auth()->user()?->can('export barang'), 403);

        $payload = ShippingLabelPdfData::forBarang($barang);

        $pdf = Pdf::loadView('exports.shipping-label-pdf', $payload)
            ->setPaper([0, 0, ShippingLabelPdfData::PAPER_WIDTH_PT, ShippingLabelPdfData::PAPER_HEIGHT_PT])
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isRemoteEnabled', true);

        return $pdf->stream(ShippingLabelPdfData::filename($barang));
    }