private function markBarangApproved(array &$data): void
    {
        $data['approval_status'] = Barang::APPROVAL_APPROVED;
        $data['approved_by'] = auth()->id();
        $data['approved_at'] = now();
        $data['rejection_note'] = null;
    }