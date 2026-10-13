private function markingLookupForBarangs(EloquentCollection $barangs): Collection
    {
        $codes = $barangs->pluck('marking')
            ->map(static fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        $codeCol = Marking::codeColumn();
        if ($codeCol === null) {
            return collect();
        }

        return Marking::query()
            ->with([
                'sales:id,name',
                'branch:id,code,name',
            ])
            ->whereIn($codeCol, $codes)
            ->get()
            ->keyBy(static fn (Marking $m) => $m->displayCode());
    }