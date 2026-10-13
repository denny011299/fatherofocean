<div class="modal fade" id="modalSalaryDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content glass">
            <div class="modal-header border-0">
                <h5 class="modal-title">{{ __('sales-salary.detail_title') }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="salaryDetailBody"></div>
        </div>
    </div>
</div>

@if($canUploadSalaryProof)
<div class="modal fade" id="modalSalaryUpload" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass">
            <div class="modal-header border-0">
                <h5 class="modal-title">{{ __('sales-salary.modal_upload_title') }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="salaryUploadForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <p class="small mb-3" style="color:var(--text-muted)">
                        {{ __('sales-salary.period_label', ['period' => '']) }}
                        <span class="fw-700" id="salaryUploadPeriod" style="color:var(--text-primary)"></span>
                    </p>
                    <div class="mb-3">
                        <label class="form-label">{{ __('sales-salary.proof_file') }}</label>
                        <input type="file" name="proof_file" id="salaryProofFile" class="form-control" accept="image/*,.pdf" required
                            data-compress-image="true" data-compress-profile="payment">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('sales-salary.admin_note') }}</label>
                        <textarea name="admin_note" id="salaryAdminNote" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-accent" id="btnSaveSalaryProof">{{ __('common.save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
