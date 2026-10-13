public function update(Request $request, Barang $barang): JsonResponse
    {
        $old = $barang->toArray();

        $data = $request->validate([
            'no_po' => 'nullable|string|max:50',
            'marking' => 'nullable|string|max:100',
            'supplier_chinese' => 'nullable|string|max:100',
            'user_id' => 'nullable|exists:users,id',
            'warehouse_origin' => 'required|string|max:50',
            'tanggal_masuk' => 'nullable|date',
            'ctns' => 'nullable|integer|min:0',
            'pcs' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'material' => 'nullable|string|max:100',
            'form' => 'nullable|string|max:100',
            'material_desc' => 'nullable|string',
            'insurance_value' => 'nullable|numeric|min:0',
            'insurance_rate' => 'nullable|numeric|min:0|max:100',
            'insurance_nominal_standar' => 'nullable|numeric|min:0',
            'insurance_nominal_custom' => 'nullable|numeric|min:0',
            'cbm_type' => 'nullable|string|max:20',
            'rate' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'total_invoice' => 'nullable|string|max:100',
            'invoice_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'remove_invoice_file' => 'nullable|boolean',
            'weight_price' => 'nullable|numeric|min:0',
            'cbm_threshold' => 'nullable|numeric|min:0',
            'destination' => 'nullable|string|max:100',
            'other' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'notes_id' => 'nullable|string',
        ]);

        if ($request->hasFile('invoice_file')) {
            if ($barang->invoice_file) {
                Storage::disk('public')->delete($barang->invoice_file);
            }
            $data['invoice_file'] = $request->file('invoice_file')->store('invoices', 'public');
        } elseif ($request->boolean('remove_invoice_file')) {
            if ($barang->invoice_file) {
                Storage::disk('public')->delete($barang->invoice_file);
            }
            $data['invoice_file'] = null;
        }
        unset($data['remove_invoice_file']);

        $barang->update($data);
        ActivityLog::record('Barang updated', $barang, $old, $barang->fresh()->toArray());

        return response()->json(['success' => true, 'message' => 'Barang berhasil diperbarui.']);
    }