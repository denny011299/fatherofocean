private function barangValidationMessages(): array
    {
        $messages = [
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'max.string' => ':attribute maksimal :max karakter.',
            'max.file' => ':attribute maksimal :max kilobyte.',
            'unique' => ':attribute sudah digunakan.',
            'date' => ':attribute harus berformat tanggal yang valid.',
            'integer' => ':attribute harus berupa angka bulat.',
            'numeric' => ':attribute harus berupa angka.',
            'min.numeric' => ':attribute minimal :min.',
            'min.integer' => ':attribute minimal :min.',
            'max.numeric' => ':attribute maksimal :max.',
            'exists' => ':attribute yang dipilih tidak valid.',
            'file' => ':attribute harus berupa file.',
            'mimes' => ':attribute harus berformat :values.',
            'array' => ':attribute tidak valid.',
            'boolean' => ':attribute tidak valid.',
        ];

        $numericFields = [
            'price',
            'insurance_nominal_standar',
            'rate',
            'price_per_cbm',
            'weight_price',
            'cbm_threshold',
            'insurance_value',
            'insurance_rate',
            'weight',
        ];

        foreach ($numericFields as $field) {
            $messages["{$field}.numeric"] = ':attribute harus berupa angka.';
            $messages["{$field}.min"] = ':attribute minimal :min.';
            $messages["{$field}.max"] = ':attribute maksimal :max.';
        }

        $messages['insurance_rate.max'] = 'Rate asuransi maksimal :max%.';

        return $messages;
    }