private function createInvoiceForBarang(Barang $barang): ?Invoice
    {
        if (! $barang->user_id || $barang->invoiceItems()->exists()) {
            return null;
        }

        $pricePerCbm = (float) ($barang->price_per_cbm ?? 0);
        if ($pricePerCbm <= 0) {
            return null;
        }

        $insuranceAmount = (float) $barang->insurance_value * ((float) $barang->insurance_rate / 100);
        $due = \App\Support\InvoiceDueResolver::resolveForBarang($barang);

        $invoice = Invoice::create([
            'invoice_no' => Invoice::generateNumber(),
            'user_id' => $barang->user_id,
            'price_per_cbm' => $pricePerCbm,
            'status' => \App\Support\InvoiceDueResolver::statusForResolved($due, 'draft'),
            'due_date' => $due['due_date'],
            'created_by' => auth()->id(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'barang_id' => $barang->id,
            'cbm' => $barang->total_cbm,
            'price_per_cbm' => $pricePerCbm,
            'insurance' => $insuranceAmount,
            'extra' => (float) ($barang->extra_charge ?? 0),
        ]);

        $invoice->recalculate();

        return $invoice;
    }