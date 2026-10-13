private function barangCityFilterOptions(): Collection
    {
        $branchIds = Marking::query()
            ->visibleToUser()
            ->whereNotNull('branch_id')
            ->whereHas('barangs')
            ->distinct()
            ->pluck('branch_id');

        if ($branchIds->isEmpty()) {
            return collect();
        }

        return MasterBranch::query()
            ->whereIn('id', $branchIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(static fn (MasterBranch $branch) => trim((string) ($branch->name ?: $branch->code)))
            ->filter(static fn (string $label) => $label !== '')
            ->unique()
            ->values();
    }