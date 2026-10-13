private function syncWarehouseOriginFromGudang(array &$data): void
    {
        if (empty($data['gudang_china_id'])) {
            return;
        }

        $name = DB::table('master_gudang_chinas')
            ->where('id', $data['gudang_china_id'])
            ->value('name');

        if ($name !== null && $name !== '') {
            $data['warehouse_origin'] = $name;
        }
    }