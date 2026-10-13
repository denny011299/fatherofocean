private function resolveMarking(Request $request): ?Marking
    {
        $cached = $this->resolvedMarking($request);
        if ($cached !== null) {
            return $cached;
        }

        $markingValue = $request->input('marking');
        if ($markingValue === null || $markingValue === '') {
            return null;
        }

        $marking = Marking::visibleToUser()
            ->whereBarangMarkingValue((string) $markingValue)
            ->first();

        if ($marking !== null) {
            $request->attributes->set('resolved_marking', $marking);
        }

        return $marking;
    }