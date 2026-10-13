private function scheduleAfterDestroySideEffects(array $invoiceIds, array $snapshot): void
    {
        app()->terminating(function () use ($invoiceIds, $snapshot): void {
            ActivityLog::record('Barang deleted', null, $snapshot);

            foreach ($invoiceIds as $invoiceId) {
                $invoice = Invoice::query()->find((int) $invoiceId);
                if (! $invoice) {
                    continue;
                }

                $invoice->recalculate();
                if (! $invoice->items()->exists()) {
                    $invoice->delete();
                }
            }
        });
    }