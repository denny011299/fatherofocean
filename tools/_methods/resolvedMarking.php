private function resolvedMarking(Request $request): ?Marking
    {
        $cached = $request->attributes->get('resolved_marking');

        return $cached instanceof Marking ? $cached : null;
    }