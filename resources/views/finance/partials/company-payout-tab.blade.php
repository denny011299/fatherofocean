<div class="bento-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="glass stat-card">
        <i class="bi bi-building stat-icon"></i>
        <div class="stat-label">{{ __('company-payout.net_fix_company') }}</div>
        <div class="stat-value text-accent" id="statPayoutNetFix">Rp 0</div>
    </div>
    <div class="glass stat-card">
        <i class="bi bi-wallet2 stat-icon"></i>
        <div class="stat-label">{{ __('company-payout.remaining_balance') }}</div>
        <div class="stat-value text-success" id="statPayoutRemaining">Rp 0</div>
    </div>
    <div class="glass stat-card">
        <i class="bi bi-cash-coin stat-icon"></i>
        <div class="stat-label">{{ __('company-payout.total_withdrawn') }}</div>
        <div class="stat-value text-warning" id="statTotalWithdrawn">Rp 0</div>
    </div>
</div>

<div class="glass p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label mb-1">{{ __('company-payout.filter_period') }}</label>
            <input type="month" id="filterCompanyPayoutPeriod" class="form-control" value="{{ now()->format('Y-m') }}">
        </div>
        <div class="col-md-2 ms-md-auto">
            <button type="button" class="btn btn-glass w-100" id="btnRefreshCompanyPayout" style="height:42px">
                <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
            </button>
        </div>
        @if($canManageCompanyPayout ?? false)
        <div class="col-md-3">
            <button type="button" class="btn btn-accent w-100" id="btnAddCompanyPayout" style="height:42px">
                <i class="bi bi-cash-coin me-1"></i>{{ __('company-payout.btn_add') }}
            </button>
        </div>
        @endif
    </div>
    <p class="small mb-0 mt-2" style="color:var(--text-muted)">
        <i class="bi bi-info-circle me-1"></i>{{ __('company-payout.hint') }}
    </p>
</div>

<div class="glass p-4">
    <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
        <i class="bi bi-cash-coin me-2 text-accent"></i>{{ __('company-payout.tab') }}
        <span class="small fw-normal ms-1" style="color:var(--text-muted)" id="payoutTablePeriodLabel"></span>
    </h6>
    <div class="table-responsive">
        <table id="companyPayoutTable" class="table-glass w-100">
            <thead>
                <tr>
                    <th>{{ __('company-payout.col_date') }}</th>
                    <th>{{ __('company-payout.col_amount') }}</th>
                    <th>{{ __('company-payout.col_proof') }}</th>
                    <th>{{ __('company-payout.col_note') }}</th>
                    <th>{{ __('company-payout.col_created_by') }}</th>
                    <th>{{ __('company-payout.col_created_at') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
