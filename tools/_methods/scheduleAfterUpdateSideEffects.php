private function scheduleAfterUpdateSideEffects(int $barangId, array $old, array $new): void
    {
        app()->terminating(function () use ($barangId, $old, $new): void {
            $barang = Barang::query()->find($barangId);
            if (! $barang) {
                return;
            }

            ActivityLog::record('Barang updated', $barang, $old, $new);
        });
    }