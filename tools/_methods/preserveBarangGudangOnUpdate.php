private function preserveBarangGudangOnUpdate(Request $request, Barang $barang): void
    {
        $gudangId = $request->input('gudang_china_id');
        if ($gudangId !== null && $gudangId !== '') {
            return;
        }

        if ($barang->gudang_china_id) {
            $request->merge(['gudang_china_id' => $barang->gudang_china_id]);
        }
    }