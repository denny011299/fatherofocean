private function markingCostFieldsEmpty(Request $request): bool
    {
        foreach (['cost_price_per_cbm', 'cost_weight_price'] as $field) {
            $value = $request->input($field);
            if ($value !== null && $value !== '' && (float) $value > 0) {
                return false;
            }
        }

        return true;
    }