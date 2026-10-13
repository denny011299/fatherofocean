private function scheduleAfterStoreSideEffects(int $barangId): void
    {
        app()->terminating(function () use ($barangId): void {
            $barang = Barang::query()->find($barangId);
            if (! $barang) {
                return;
            }

            ActivityLog::record('Barang created', $barang);
            app(BarangTrackingService::class)->syncStuffing($barang, auth()->id());

            $invoice = $this->createInvoiceForBarang($barang->fresh());
            if ($invoice) {
                ActivityLog::record('Invoice generated', $invoice);
            }
        });
    }