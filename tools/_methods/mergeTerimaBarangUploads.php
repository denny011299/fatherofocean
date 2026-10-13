private function mergeTerimaBarangUploads(Request $request, array &$data): void
    {
        if ($request->hasFile('good_receipt_file')) {
            $data['good_receipt_path'] = $request->file('good_receipt_file')->store('barang-receipts', 'public');
        }

        if ($request->hasFile('tracking_receipt_file')) {
            $data['tracking_receipt_path'] = $request->file('tracking_receipt_file')->store('barang-receipts', 'public');
        }

        unset($data['good_receipt_file'], $data['tracking_receipt_file']);
    }