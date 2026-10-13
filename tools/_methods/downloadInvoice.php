public function downloadInvoice(Barang $barang, ExportController $export): Response
    {
        abort_unless(auth()->user()->can('download barang invoice'), 403);

        $invoice = $barang->invoiceItems()->first()?->invoice;
        if (!$invoice) {
            abort(404);
        }

        return $export->invoicePdf($invoice);
    }