public function data(Request $request): JsonResponse
    {
        $canDownloadInvoice = auth()->user()->can('download barang invoice');

        $query = Barang::with('gudangChina', 'invoiceItems')->latest();

        $approvalFilter = $request->get('approval_status');
        if (is_string($approvalFilter) && $approvalFilter !== '') {
            $query->where('approval_status', $approvalFilter);
        }

        $barangs = $query->get()->map(function ($b) use ($canDownloadInvoice) {
            $hasFinanceInvoice = $b->invoiceItems->isNotEmpty();

            return [
                'id'                    => $b->id,
                'no_index'              => $b->no_index,
                'no_po'                 => $b->no_po,
                'marking'               => $b->marking,
                'warehouse_origin'      => $b->gudangChina?->name ?? $b->warehouse_origin,
                'gudang_china_id'       => $b->gudang_china_id,
                'ctns'                  => $b->ctns,
                'total_cbm'             => $b->total_cbm,
                'total_gross_weight'    => $b->total_gross_weight,
                'no_resi'               => $b->no_resi ?? [],
                'status'                => $b->status,
                'raw_status'            => $b->status,
                'status_badge'          => $b->status_badge,
                'approval_status'       => $b->approval_status,
                'approval_status_badge' => $b->approval_status_badge,
                'rejection_note'        => $b->rejection_note,
                'has_invoice'           => (bool) $b->invoice_file,
                'has_finance_invoice'   => $hasFinanceInvoice,
                'invoice_download_url'  => $canDownloadInvoice && $hasFinanceInvoice
                    ? route('barang.invoice.pdf', $b)
                    : null,
            ];
        });

        return response()->json($barangs);
    }