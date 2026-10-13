private function prepareBarangUpdate(Request $request, Barang $barang): array
    {
        $old = $this->barangAuditSnapshot($barang);
        $this->stripEmptyFormRows($request);
        $this->stripEmptyProductPhotoFields($request);
        $this->normalizeBarangRequest($request, isUpdate: true);

        $data = $request->validate(
            array_merge($this->barangUpdateRules(), $this->productPhotoValidationRules($request)),
            $this->barangValidationMessages(),
            $this->barangValidationAttributes()
        );

        $data['no_resi'] = $this->parseNoResiInput($data['no_resi'] ?? null);

        return ['data' => $data, 'old' => $old];
    }