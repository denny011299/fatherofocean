<div class="modal fade" id="modalCommissionDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content glass">
            <div class="modal-header border-0">
                <h5 class="modal-title">{{ __('sales-salary.commission_detail_title') }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="commissionDetailBody"></div>
        </div>
    </div>
</div>

@if($canUploadSalaryProof ?? false)
<div class="modal fade" id="modalCommissionPayout" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass">
            <div class="modal-header border-0">
                <h5 class="modal-title">{{ __('sales-salary.marking_payout_modal_title') }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="commissionPayoutForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <p class="small mb-2" style="color:var(--text-muted)" id="commissionPayoutMeta"></p>
                    <p class="fw-700 mb-3" id="commissionPayoutRemaining"></p>
                    <input type="hidden" name="sales_id" id="commissionPayoutSalesId">
                    <input type="hidden" name="marking" id="commissionPayoutMarking">
                    <input type="hidden" name="date_from" id="commissionPayoutDateFrom">
                    <input type="hidden" name="date_to" id="commissionPayoutDateTo">
                    <input type="hidden" name="partai" id="commissionPayoutPartai">
                    <div class="mb-3">
                        <label class="form-label" for="commissionPayoutAmount">{{ __('sales-salary.col_total') }}</label>
                        <input type="number" name="amount" id="commissionPayoutAmount" class="form-control" min="1" step="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="commissionPayoutDate">{{ __('company-payout.field_date') }}</label>
                        <input type="date" name="payout_date" id="commissionPayoutDate" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="commissionPayoutProof">{{ __('sales-salary.proof_file') }}</label>
                        <input type="file" name="proof_file" id="commissionPayoutProof" class="form-control" accept="image/*,.pdf" required
                            data-compress-image="true" data-compress-profile="payment">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="commissionPayoutNote">{{ __('sales-salary.admin_note') }}</label>
                        <textarea name="note" id="commissionPayoutNote" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-accent" id="btnSaveCommissionPayout">{{ __('common.save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
