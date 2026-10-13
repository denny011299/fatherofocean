private function resolveMarkingPricingRow(Marking $marking, mixed $cbmType): ?MarkingPricing
    {
        $cached = request()->attributes->get('resolved_marking_pricings');
        $pricings = $cached instanceof Collection
            ? $cached
            : $marking->pricings()->with('pricingItem:id,name')->orderBy('id')->get();

        return \App\Support\MarkingPricingResolver::pickRow(
            $pricings,
            \App\Support\MarkingPricingResolver::normalizeKey($cbmType),
        );
    }