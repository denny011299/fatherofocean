private function formViewData(): array
    {
        return [
            'cbmTypes' => MasterCbmType::active()->orderBy('name')->get(),
            'classifications' => MasterClassification::active()->orderBy('name')->get(),
            'countUnits' => MasterCountUnit::active()->orderBy('name')->get(),
            'markings' => Marking::forDropdown()->visibleToUser()->get(['id', 'code', 'customer_name', 'user_id']),
            'packings' => MasterPacking::active()->orderBy('name')->get(),
            'gudangList' => MasterGudangChina::active()->orderBy('name')->get(),
        ];
    }