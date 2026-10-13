public function lookupIndex(Request $request): JsonResponse
    {
        $barang = Barang::with('dimensions')
            ->where('no_index', $request->get('no_index'))
            ->first();

        if (!$barang) {
            return response()->json(['found' => false]);
        }

        return response()->json(['found' => true, 'data' => $barang]);
    }