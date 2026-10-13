private function applyApprovalOnSave(array &$data): void
    {
        if ($this->canAutoApproveBarang()) {
            $data['approval_status'] = Barang::APPROVAL_APPROVED;
            $data['approved_by'] = auth()->id();
            $data['approved_at'] = now();
            $data['rejection_note'] = null;

            return;
        }

        $data['approval_status'] = Barang::APPROVAL_PENDING;
        $data['approved_by'] = null;
        $data['approved_at'] = null;
        $data['rejection_note'] = null;
    }