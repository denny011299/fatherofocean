public function destroy(Barang $barang): JsonResponse
    {
        DB::transaction(function () use ($barang) {
            $invoiceIds = $barang->invoiceItems()->pluck('invoice_id')->unique();

            ActivityLog::record('Barang deleted', $barang);
            $barang->invoiceItems()->delete();
            $barang->containers()->detach();
            $barang->delete();

            foreach ($invoiceIds as $invId) {
                Invoice::find($invId)?->recalculate();
            }
        });

        return response()->json(['success' => true, 'message' => 'Barang berhasil dihapus.']);
    }