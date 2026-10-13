private function barangSavedJson(Barang $barang, string $message, bool $toIndex = false): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'redirect' => $toIndex ? route('barang.index') : route('barang.edit', $barang),
            'data' => ['id' => $barang->id],
        ]);
    }