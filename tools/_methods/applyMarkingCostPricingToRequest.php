private function applyMarkingCostPricingToRequest(Request $request): void
    {
        $marking = $this->resolveMarking($request);
        if ($marking === null) {
            return;
        }

        $row = $this->pickMarkingPricingRow(
            $marking,
            $this->normalizeMarkingMatchKey($request->input('cbm_type'))
        );

        $rates = \App\Support\MarkingPricingResolver::modalRatesFromPricingRow($row);

        $request->merge([
            'cost_price_per_cbm' => (string) $rates['volume_price'],
            'cost_weight_price' => (string) $rates['gw_price'],
        ]);
    }