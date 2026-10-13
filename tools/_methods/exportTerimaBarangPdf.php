public function exportTerimaBarangPdf(Barang $barang): Response
    {
        abort_unless(auth()->user()->can('export barang'), 403);

        $payload = TerimaBarangPdfData::forBarang($barang);
        $pdf = Pdf::loadView('exports.terima-barang-pdf', $payload);

        return $pdf->download(TerimaBarangPdfData::filename($barang));
    }