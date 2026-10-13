private function executePreparedBarangUpdate(Barang $barang, Request $request, array $prepared): Barang
    {
        $data = $prepared['data'];
        $old = $prepared['old'];

        if ($request->hasFile('invoice_file')) {
            if ($barang->invoice_file) {
                Storage::disk('public')->delete($barang->invoice_file);
            }
            $data['invoice_file'] = $this->storeOriginalPackingListFile(
                $request->file('invoice_file'),
                (string) $barang->no_index,
            );
        } elseif ($request->boolean('remove_invoice_file')) {
            if ($barang->invoice_file) {
                Storage::disk('public')->delete($barang->invoice_file);
            }
            $data['invoice_file'] = null;
        }

        $this->syncTerimaBarangUploads($request, $barang, $data);

        unset(
            $data['remove_invoice_file'],
            $data['remove_good_receipt_file'],
            $data['remove_tracking_receipt_file'],
            $data['good_receipt_file'],
            $data['tracking_receipt_file'],
            $data['dimensions'],
            $data['products'],
            $data['additional_prices'],
            $data['company_loyalty_amount'],
        );

        $this->syncWarehouseOriginFromGudang($data);
        $this->ensureTanggalMasuk($data);

        $barang = $barang->updateBarang($data, $request);

        Barang::deferAfterUpdate((int) $barang->id, $old, $this->barangAuditSnapshot($barang));

        return $barang;
    }