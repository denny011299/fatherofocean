public function executeBarangUpdate(Barang $barang, Request $request): Barang
    {
        $this->stripEmptyFormRows($request);
        $this->stripEmptyProductPhotoFields($request);
        $this->normalizeBarangRequest($request, isUpdate: true);

        $prepared = $this->prepareBarangUpdate($request, $barang);

        return $this->executePreparedBarangUpdate($barang, $request, $prepared);
    }