private function normalizeBarangRequest(Request $request): void
    {
        $numericFields = [
            'ctns',
            'pcs',
            'price',
            'insurance_value',
            'insurance_rate',
            'insurance_nominal_standar',
            'rate',
            'weight',
            'price_per_cbm',
            'weight_price',
            'cbm_threshold',
        ];

        foreach ($numericFields as $field) {
            if (!$request->has($field)) {
                continue;
            }

            $value = $request->input($field);
            if ($value === null || $value === '') {
                $request->merge([$field => null]);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $normalized = preg_replace('/[^0-9.,\-]/', '', $value) ?? '';
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
            $request->merge([$field => $normalized === '' || $normalized === '-' ? null : $normalized]);
        }

        $dimensions = $request->input('dimensions');
        if (!is_array($dimensions)) {
            return;
        }

        $dimensionNumericFields = [
            'qty_ctn',
            'panjang',
            'lebar',
            'tinggi',
            'ctn_count',
            'qty',
            'gw_per_ctn',
            'gross_weight',
        ];

        foreach ($dimensions as $index => $dimension) {
            if (!is_array($dimension)) {
                continue;
            }

            foreach ($dimensionNumericFields as $field) {
                if (array_key_exists($field, $dimension) && $dimension[$field] === '') {
                    $dimensions[$index][$field] = null;
                }
            }
        }

        $request->merge(['dimensions' => $dimensions]);
    }