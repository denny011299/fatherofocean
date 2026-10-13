private function barangSavedPayload(Barang $barang, string $message, bool $toIndex = false): array
    {
        return [
            'success' => true,
            'message' => $message,
            'redirect' => $toIndex ? route('barang.index') : route('barang.edit', $barang),
            'data' => ['id' => $barang->id],
        ];
    }