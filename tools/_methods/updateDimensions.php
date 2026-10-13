public function updateDimensions(Request $request, Barang $barang): JsonResponse
    {
        $data = $request->validate([
            'dimensions' => 'required|array',
            'dimensions.*.id' => 'nullable|exists:barang_dimensions,id',
            'dimensions.*.item_name' => 'nullable|string|max:100',
            'dimensions.*.ctn_no' => 'nullable|string|max:30',
            'dimensions.*.qty_ctn' => 'integer|min:0',
            'dimensions.*.panjang' => 'numeric|min:0',
            'dimensions.*.lebar' => 'numeric|min:0',
            'dimensions.*.tinggi' => 'numeric|min:0',
            'dimensions.*.ctn_count' => 'integer|min:1',
            'dimensions.*.qty' => 'integer|min:0',
            'dimensions.*.count_by' => 'nullable|string|max:20',
            'dimensions.*.gw_per_ctn' => 'numeric|min:0',
            'dimensions.*.gross_weight' => 'numeric|min:0',
        ]);

        $keepIds = [];
        foreach ($data['dimensions'] as $dim) {
            if (!empty($dim['id'])) {
                $existing = BarangDimension::find($dim['id']);
                $existing?->update($dim);
                $keepIds[] = $dim['id'];
            } else {
                $created = $barang->dimensions()->create($dim);
                $keepIds[] = $created->id;
            }
        }

        $barang->dimensions()->whereNotIn('id', $keepIds)->delete();
        $barang->recalculateTotals();

        return response()->json([
            'success' => true,
            'message' => 'Dimensi berhasil diperbarui.',
            'total_cbm' => $barang->fresh()->total_cbm,
            'total_gw' => $barang->fresh()->total_gross_weight,
        ]);
    }