private function ensureTanggalMasuk(array &$data): void
    {
        if (! array_key_exists('tanggal_masuk', $data) || $data['tanggal_masuk'] === null || $data['tanggal_masuk'] === '') {
            $data['tanggal_masuk'] = now()->toDateString();
        }
    }