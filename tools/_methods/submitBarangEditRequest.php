private function submitBarangEditRequest(Barang $barang, Request $request, array $prepared): JsonResponse
    {
        $data = $prepared['data'];

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

        try {
            app(BarangEditRequestService::class)->createFromUpdate($barang, $request, $data);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('barang.edit_request_submitted'),
            'redirect' => route('barang.index', ['tab' => 'pengajuan-edit']),
            'submitted_for_approval' => true,
            'data' => ['id' => $barang->id],
        ]);
    }