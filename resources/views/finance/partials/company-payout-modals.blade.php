@if($canManageCompanyPayout ?? false)
<div class="modal fade" id="modalCompanyPayout" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass">
            <div class="modal-header border-0">
                <h5 class="modal-title">{{ __('company-payout.modal_title') }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="companyPayoutForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="payoutAmount">{{ __('company-payout.field_amount') }}</label>
                        <input type="number" name="amount" id="payoutAmount" class="form-control" min="1" step="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="payoutDate">{{ __('company-payout.field_date') }}</label>
                        <input type="date" name="payout_date" id="payoutDate" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="payoutProofFile">{{ __('company-payout.field_proof') }}</label>
                        <input type="file" name="proof_file" id="payoutProofFile" class="form-control" accept="image/*,.pdf" required
                            data-compress-image="true" data-compress-profile="payment">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="payoutNote">{{ __('company-payout.field_note') }}</label>
                        <textarea name="note" id="payoutNote" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-accent" id="btnSaveCompanyPayout">{{ __('common.save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
