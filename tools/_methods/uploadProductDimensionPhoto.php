public function uploadProductDimensionPhoto(Request $request, Barang $barang, BarangProduct $product): JsonResponse
    {
        abort_unless(auth()->user()->can('edit barang'), 403);
        $this->assertBarangVisibleToUser($barang);

        if ((int) $product->barang_id !== (int) $barang->id) {
            abort(404);
        }

        if ($product->parent_id !== null) {
            return response()->json([
                'success' => false,
                'message' => __('barang.dimension_proof_parent_only'),
            ], 422);
        }

        $validated = $request->validate([
            'dimension' => 'required|in:length,width,height',
            'remove' => 'nullable|boolean',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $dimension = $validated['dimension'];
        $pathKey = "{$dimension}_photo_path";

        if ($request->boolean('remove')) {
            if ($product->{$pathKey}) {
                Storage::disk('public')->delete($product->{$pathKey});
            }
            $product->{$pathKey} = null;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => __('barang.dimension_proof_removed'),
                'dimension' => $dimension,
                "has_{$dimension}_photo" => false,
                $pathKey => null,
                "{$dimension}_photo_url" => null,
            ]);
        }

        if (! $request->hasFile('photo')) {
            return response()->json([
                'success' => false,
                'message' => __('barang.dimension_proof_file_required'),
            ], 422);
        }

        $uploaded = $request->file('photo');
        if ($product->{$pathKey}) {
            Storage::disk('public')->delete($product->{$pathKey});
        }
        $product->{$pathKey} = $uploaded->store('barang-products/dimensions', 'public');
        $product->save();

        $path = BarangProduct::normalizeStoragePath($product->{$pathKey});
        $url = \App\Support\PublicStorageUrl::url($path);

        return response()->json([
            'success' => true,
            'message' => __('barang.dimension_proof_upload_success'),
            'dimension' => $dimension,
            "has_{$dimension}_photo" => true,
            $pathKey => $path,
            "{$dimension}_photo_url" => $url,
        ]);
    }