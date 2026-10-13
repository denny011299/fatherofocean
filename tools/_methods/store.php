public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'no_index' => 'required|string|max:50|unique:barangs',
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
            'weight_price' => 'nullable|numeric|min:0',
            'cbm_threshold' => 'nullable|numeric|min:0',
            'destination' => 'nullable|string|max:100',
            'other' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'notes_id' => 'nullable|string',
            'dimensions' => 'nullable|array',
            'dimensions.*.item_name' => 'nullable|string|max:100',
            'dimensions.*.ctn_no' => 'nullable|string|max:30',
            'dimensions.*.qty_ctn' => 'nullable|integer|min:0',
            'dimensions.*.panjang' => 'nullable|numeric|min:0',
            'dimensions.*.lebar' => 'nullable|numeric|min:0',
            'dimensions.*.tinggi' => 'nullable|numeric|min:0',
            'dimensions.*.ctn_count' => 'nullable|integer|min:1',
            'dimensions.*.qty' => 'nullable|integer|min:0',
            'dimensions.*.count_by' => 'nullable|string|max:20',
            'dimensions.*.gw_per_ctn' => 'nullable|numeric|min:0',
            'dimensions.*.gross_weight' => 'nullable|numeric|min:0',
        ]);

        if ($request->hasFile('invoice_file')) {
            $data['invoice_file'] = $request->file('invoice_file')->store('invoices', 'public');
        }

        $dimensions = $data['dimensions'] ?? [];
        unset($data['dimensions']);

        $data['status'] = 'inbound';
        $data['created_by'] = auth()->id();

        if (!empty($data['marking']) && empty($data['user_id'])) {
            $marking = Marking::where('code', $data['marking'])->first();
            if ($marking) {
                $data['user_id'] = $marking->user_id;
            }
        }

        $barang = DB::transaction(function () use ($data, $dimensions) {
            $barang = Barang::create($data);

            if (!empty($dimensions)) {
                foreach ($dimensions as $dim) {
                    $barang->dimensions()->create($dim);
                }
                $barang->recalculateTotals();
            }

            return $barang;
        });

        ActivityLog::record('Barang created', $barang);

        return response()->json(['success' => true, 'message' => 'Barang berhasil ditambahkan.', 'data' => $barang->load('dimensions')]);
    }