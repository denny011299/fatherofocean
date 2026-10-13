private function barangCrudJsonResponse(Barang $barang, string $message, bool $redirectToIndex = false): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'redirect' => $redirectToIndex ? route('barang.index') : route('barang.edit', $barang),
            'data' => ['id' => $barang->id],
        ];

        if (! $redirectToIndex) {
            $barang->load(['products', 'additionalPrices']);
            $payload['products'] = $this->mapProductsForForm($barang->products);
            $payload['additional_prices'] = $this->mapAdditionalPricesForForm($barang->additionalPrices);
            $payload['terima_barang_pdf_url'] = $this->terimaBarangPdfUrl($barang);
            $payload['products_export_url'] = $this->terimaBarangPdfUrl($barang);
            $payload['original_pl_download_url'] = $this->originalPackingListDownloadUrl($barang);
        }

        return response()->json($payload);
    }