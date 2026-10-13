private function pickMarkingPricingRow(Marking $marking, string $cbmType): ?MarkingPricing
    {
        $cached = request()->attributes->get('resolved_marking_pricings');
        $pricings = $cached instanceof Collection ? $cached : $marking->pricings()
            ->with('pricingItem:id,name')
            ->orderBy('id')
            ->get();

        if ($pricings->isEmpty()) {
            return null;
        }

        if ($cbmType !== '') {
            $exact = $pricings->first(
                fn (MarkingPricing $row) => $this->normalizeMarkingMatchKey($row->pricingItem?->name) === $cbmType
            );
            if ($exact !== null) {
                return $exact;
            }

            $partial = $pricings->first(function (MarkingPricing $row) use ($cbmType) {
                $name = $this->normalizeMarkingMatchKey($row->pricingItem?->name);

                return str_contains($name, $cbmType) || str_contains($cbmType, $name);
            });
            if ($partial !== null) {
                return $partial;
            }
        }

        $lartas = $pricings->first(
            fn (MarkingPricing $row) => str_contains($this->normalizeMarkingMatchKey($row->pricingItem?->name), 'lartas')
        );
        if ($lartas !== null) {
            return $lartas;
        }

        $umum = $pricings->first(
            fn (MarkingPricing $row) => str_contains($this->normalizeMarkingMatchKey($row->pricingItem?->name), 'umum')
        );

        return $umum ?? $pricings->first();
    }