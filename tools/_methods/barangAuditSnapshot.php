private function barangAuditSnapshot(Barang $barang): array
    {
        return $barang->only([
            'id', 'no_index', 'marking', 'status', 'user_id', 'gudang_china_id',
            'total_cbm', 'total_gross_weight', 'price_per_cbm', 'weight_price',
            'approval_status', 'tanggal_masuk',
        ]);
    }