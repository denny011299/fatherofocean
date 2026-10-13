<div class="modal fade" id="barangEditRequestModal" tabindex="-1" aria-labelledby="barangEditRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-barang-edit-request">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700" id="barangEditRequestModalLabel">
                    <i class="bi bi-clipboard2-data me-1 text-accent"></i>{{ __('barang.edit_pengajuan_view_title') }}
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.close') }}"></button>
            </div>
            <div class="modal-body">
                <div id="barangEditRequestSummary" class="barang-edit-diff-summary mb-3"></div>
                <div id="barangEditRequestDiffPanel" class="barang-edit-diff-panel"></div>
                <div id="barangEditRequestEmpty" class="barang-edit-diff-empty text-center py-5 d-none">
                    <i class="bi bi-inbox" aria-hidden="true"></i>
                    <p class="text-muted-glass mb-0 mt-2">{{ __('barang.edit_pengajuan_no_diff') }}</p>
                </div>
            </div>
            <div class="modal-footer" id="barangEditRequestModalFooter">
                <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.close') }}</button>
            </div>
        </div>
    </div>
</div>
