<div class="modal fade" id="repriceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-tags-fill me-1 text-warning"></i>RE-PRICING</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="glass-solid p-3 mb-3">
                    <div class="reprice-meta-grid">
                        <div class="reprice-meta-item reprice-meta-index">
                            <div class="reprice-meta-label">{{ __('barang.label_no_index') }}</div>
                            <div class="reprice-meta-value text-white fw-700" id="rpIndex">-</div>
                        </div>
                        <div class="reprice-meta-item reprice-meta-marking">
                            <div class="reprice-meta-label">{{ __('barang.col_marking') }}</div>
                            <div class="reprice-meta-value text-white fw-600" id="rpMarking">-</div>
                        </div>
                        <div class="reprice-meta-item reprice-meta-cbm">
                            <div class="reprice-meta-label">CBM</div>
                            <div class="reprice-meta-value text-accent fw-700" id="rpCbm">-</div>
                        </div>
                        <div class="reprice-meta-item reprice-meta-gw">
                            <div class="reprice-meta-label">{{ __('finance.repricing_gross_weight') }}</div>
                            <div class="reprice-meta-value text-white fw-600" id="rpGw">-</div>
                        </div>
                    </div>
                    <div id="rpRuleHint" class="small text-muted-glass mt-2"></div>
                    <div id="rpStatusBanner" class="d-none mt-2">
                        <span class="badge-glass badge-status-pending" id="rpStatusBadge"></span>
                    </div>
                </div>
                <form id="repriceForm" enctype="multipart/form-data">
                    <input type="hidden" id="rpBarangId">
                    <input type="hidden" name="pricing_mode" id="rpPricingMode" value="cbm">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('finance.repricing_price_per_cbm') }}</label>
                            <input type="text" name="price_per_cbm" id="rpPrice" class="form-control mask-currency" required>
                            <div class="form-text text-muted-glass" id="rpPriceHint"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('finance.repricing_price_per_kg') }}</label>
                            <input type="text" name="weight_price" id="rpWeightPrice" class="form-control mask-currency" required>
                            <div class="form-text text-muted-glass" id="rpWeightHint"></div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" for="rpThreshold">{{ __('finance.repricing_threshold') }}</label>
                            <input type="number" name="weight_threshold_kg" id="rpThreshold" class="form-control" step="1" min="0" inputmode="numeric" required>
                            <div class="form-text text-muted-glass" id="rpThresholdHint"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="rpMinCharge">{{ __('finance.repricing_min_charge') }}</label>
                            <input type="number" name="minimum_charge_cbm" id="rpMinCharge" class="form-control" step="0.01" min="0" inputmode="decimal">
                            <div class="form-text text-muted-glass" id="rpMinChargeHint"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="rpExtra">{{ __('finance.repricing_extra_charge') }}</label>
                            <input type="text" name="extra_charge" id="rpExtra" class="form-control mask-currency" value="0">
                        </div>
                    </div>
                    @can('edit harga modal')
                    <div class="glass-solid p-3 mb-3 border border-warning border-opacity-25">
                        <div class="fw-600 mb-2 small text-warning"><i class="bi bi-lock-fill me-1"></i>{{ __('finance.cost_price_section') }}</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('finance.cost_price_per_cbm') }}</label>
                                <input type="text" name="cost_price_per_cbm" id="rpCostPrice" class="form-control mask-currency">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('finance.cost_weight_price') }}</label>
                                <input type="text" name="cost_weight_price" id="rpCostWeightPrice" class="form-control mask-currency">
                            </div>
                        </div>
                        <button type="button" id="rpSaveCostBtn" class="btn btn-glass btn-sm mt-3">
                            <i class="bi bi-save me-1"></i>{{ __('finance.save_cost_price') }}
                        </button>
                    </div>
                    @endcan
                    <div class="mb-3">
                        <label class="form-label">{{ __('finance.repricing_reason') }} <span class="text-danger">*</span></label>
                        <textarea name="reason" id="rpReason" class="form-control" rows="3" maxlength="1000" placeholder="{{ __('finance.repricing_reason_placeholder') }}" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('finance.repricing_proof') }} <span class="text-danger">*</span></label>
                        <input type="file" name="proof_image" id="rpProof" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                        <div class="form-text text-muted-glass">{{ __('finance.repricing_proof_hint') }}</div>
                    </div>
                    <div class="glass-solid p-3 mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted-glass" id="rpCalcCbmLabel">{{ __('finance.repricing_calc_cbm') }}</span>
                            <span class="text-white" id="rpCalcCbm">Rp 0</span>
                        </div>
                        <div class="d-flex justify-content-between small mb-1" id="rpCalcWeightRow">
                            <span class="text-muted-glass" id="rpCalcWeightLabel">{{ __('finance.repricing_calc_weight') }}</span>
                            <span class="text-white" id="rpCalcWeight">Rp 0</span>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted-glass">{{ __('finance.repricing_extra_charge') }}</span>
                            <span class="text-white" id="rpCalcExtra">Rp 0</span>
                        </div>
                        <hr style="border-color:var(--glass-border);margin:.5rem 0">
                        <div class="d-flex justify-content-between fw-700">
                            <span class="text-white">{{ __('finance.repricing_estimasi_total') }}</span>
                            <span class="text-accent" id="rpCalcTotal">Rp 0</span>
                        </div>
                    </div>
                    @canany(['submit repricing', 'change price'])
                    <button type="submit" id="rpSubmitBtn" class="btn btn-accent w-100 py-2">
                        <i class="bi bi-check-lg me-1" id="rpSubmitIcon"></i>{{ __('finance.repricing_submit') }}
                    </button>
                    @endcanany
                    <div id="rpPendingInfo" class="d-none glass-solid p-3 mt-3">
                        <div class="badge-glass badge-status-pending mb-2"><i class="bi bi-hourglass-split me-1"></i>{{ __('finance.repricing_pending') }}</div>
                        <div class="small text-muted-glass" id="rpPendingMeta"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
