private function dispatchBarangAfterStore(int $barangId, ?int $actorId): void
    {
        $sideEffects = $this->barangSideEffects;
        $pending = dispatch(static function () use ($sideEffects, $barangId, $actorId): void {
            $sideEffects->afterStore($barangId, $actorId);
        });

        if ($pending instanceof PendingDispatch) {
            $pending->afterResponse();
        }
    }