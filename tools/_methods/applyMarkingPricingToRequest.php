private function applyMarkingPricingToRequest(Request $request): void
    {
        $marking = $this->resolveMarking($request);
        if ($marking === null) {
            return;
        }

        $row = $this->pickMarkingPricingRow(
            $marking,
            $this->normalizeMarkingMatchKey($request->input('cbm_type'))
        );

        $volumePrice = $row !== null ? (float) ($row->volume_price ?? 0) : 0.0;
        $gwPrice = $row !== null ? (float) ($row->gw_price ?? 0) : 0.0;

        $request->merge([
            'price_per_cbm' => (string) $volumePrice,
            'weight_price' => (string) $gwPrice,
        ]);
    }