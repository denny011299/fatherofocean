private function applyDailyRateToRequest(Request $request, bool $isUpdate): void
    {
        if ($isUpdate) {
            $submitted = $request->input('rate');
            if ($submitted !== null && $submitted !== '') {
                return;
            }
        }

        $value = SystemSetting::getValue('daily_rate');
        if ($value === null || $value === '') {
            return;
        }

        $request->merge(['rate' => (string) $value]);
    }