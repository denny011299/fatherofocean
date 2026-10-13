private function syncTerimaBarangUploads(Request $request, Barang $barang, array &$data): void
    {
        if ($request->hasFile('good_receipt_file')) {
            if ($barang->good_receipt_path) {
                Storage::disk('public')->delete($barang->good_receipt_path);
            }
            $data['good_receipt_path'] = $request->file('good_receipt_file')->store('barang-receipts', 'public');
        } elseif ($request->boolean('remove_good_receipt_file')) {
            if ($barang->good_receipt_path) {
                Storage::disk('public')->delete($barang->good_receipt_path);
            }
            $data['good_receipt_path'] = null;
        }

        if ($request->hasFile('tracking_receipt_file')) {
            if ($barang->tracking_receipt_path) {
                Storage::disk('public')->delete($barang->tracking_receipt_path);
            }
            $data['tracking_receipt_path'] = $request->file('tracking_receipt_file')->store('barang-receipts', 'public');
        } elseif ($request->boolean('remove_tracking_receipt_file')) {
            if ($barang->tracking_receipt_path) {
                Storage::disk('public')->delete($barang->tracking_receipt_path);
            }
            $data['tracking_receipt_path'] = null;
        }
    }