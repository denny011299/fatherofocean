private function applyBarangListFilters(Builder $query, Request $request): void
    {
        $tanggalFrom = $request->string('tanggal_from')->toString();
        $tanggalTo = $request->string('tanggal_to')->toString();

        if ($tanggalFrom !== '' && $tanggalTo !== '') {
            $from = Carbon::parse($tanggalFrom)->startOfDay();
            $to = Carbon::parse($tanggalTo)->endOfDay();
            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
            $query->whereBetween('tanggal_masuk', [$from, $to]);
        } elseif ($tanggalFrom !== '') {
            $query->whereDate('tanggal_masuk', Carbon::parse($tanggalFrom)->toDateString());
        } elseif ($tanggalTo !== '') {
            $query->whereDate('tanggal_masuk', '<=', Carbon::parse($tanggalTo)->toDateString());
        }

        $noIndex = trim($request->string('no_index')->toString());
        if ($noIndex !== '') {
            $query->where('no_index', 'like', '%' . $noIndex . '%');
        }

        $marking = trim($request->string('marking')->toString());
        if ($marking !== '') {
            $query->where('marking', $marking);
        }

        $status = $request->string('status')->toString();
        if ($status !== '' && in_array($status, self::STATUS_FILTER_VALUES, true)) {
            $query->where('status', $status);
        }

        $destination = trim($request->string('destination')->toString());
        if ($destination !== '') {
            $query->where('destination', $destination);
        }

        $gudangId = $request->input('gudang_china_id');
        if ($gudangId !== null && $gudangId !== '') {
            $query->where('gudang_china_id', $gudangId);
        }

        $approvalFilter = $request->get('approval_status');
        if (is_string($approvalFilter) && $approvalFilter !== '') {
            $query->where('approval_status', $approvalFilter);
        }
    }