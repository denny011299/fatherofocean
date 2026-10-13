private function canAutoApproveBarang(): bool
    {
        return auth()->user()?->can('approve barang') ?? false;
    }