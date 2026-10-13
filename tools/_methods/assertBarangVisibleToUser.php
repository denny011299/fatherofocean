private function assertBarangVisibleToUser(Barang $barang): void
    {
        abort_unless(Marking::barangVisibleToUser($barang), 403);
    }