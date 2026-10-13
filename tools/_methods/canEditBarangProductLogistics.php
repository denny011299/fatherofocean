private function canEditBarangProductLogistics(): bool
    {
        return auth()->user()?->can('edit barang product logistics') ?? false;
    }