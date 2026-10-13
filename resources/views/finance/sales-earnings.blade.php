@extends('layouts.app')
@section('title', __('sales-revenue.title'))

@push('styles')
<style>
    #salesSalaryTable .btn,
    #salesRevenueTable .btn,
    #salesIncentiveTable .btn {
        width: auto;
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
    }
    .sales-revenue-table-wrap {
        position: relative;
    }
    .finance-table-loading {
        position: absolute;
        inset: 0;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        background: rgba(15, 23, 42, 0.35);
        border-radius: inherit;
    }
    [data-theme="light"] .finance-table-loading {
        background: rgba(255, 255, 255, 0.55);
    }
</style>
@endpush

@section('content')
<div class="bento-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="glass stat-card">
        <div class="stat-label">{{ ($isSupervisorOnly ?? false) ? __('finance-earnings.estimasi_supervisor') : __('finance-earnings.estimasi_sales') }}</div>
        <div class="stat-value text-warning" id="statEstimasi">Rp {{ number_format($totals['estimasi'], 0, ',', '.') }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">{{ ($isSupervisorOnly ?? false) ? __('finance-earnings.fix_supervisor') : __('finance-earnings.fix_sales') }}</div>
        <div class="stat-value text-accent" id="statFix">Rp {{ number_format($totals['fix'], 0, ',', '.') }}</div>
    </div>
    @if($canViewIncentive && !$isSales)
    <div class="glass stat-card">
        <div class="stat-label">{{ __('sales-incentive.stat_estimasi') }}</div>
        <div class="stat-value text-warning" id="statIncentiveEstimasi">Rp {{ number_format($incentiveTotals['estimasi'] ?? 0, 0, ',', '.') }}</div>
        <div class="small mt-1" style="color:var(--text-muted)" id="statIncentivePeriodHint">{{ __('sales-incentive.stat_period_hint', ['period' => $incentivePeriod]) }}</div>
    </div>
    @endif
</div>

<div class="finance-ops-nav glass mb-4">
    <div class="finance-ops-segment" id="salesEarningsTabs" role="tablist" aria-label="{{ __('sales-revenue.title') }}">
        <button type="button"
                class="finance-ops-tab is-active"
                id="tab-commission"
                role="tab"
                aria-selected="true"
                aria-controls="tab-commission-pane"
                data-bs-target="#tab-commission-pane">
            <span class="finance-ops-tab-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
            <span class="finance-ops-tab-text">{{ __('sales-salary.tab_commission') }}</span>
        </button>
        <button type="button"
                class="finance-ops-tab"
                id="tab-salary"
                role="tab"
                aria-selected="false"
                aria-controls="tab-salary-pane"
                data-bs-target="#tab-salary-pane">
            <span class="finance-ops-tab-icon"><i class="bi bi-bank" aria-hidden="true"></i></span>
            <span class="finance-ops-tab-text">{{ __('sales-salary.tab_salary') }}</span>
        </button>
        @if($canViewIncentive)
        <button type="button"
                class="finance-ops-tab"
                id="tab-incentive"
                role="tab"
                aria-selected="false"
                aria-controls="tab-incentive-pane"
                data-bs-target="#tab-incentive-pane">
            <span class="finance-ops-tab-icon"><i class="bi bi-gift" aria-hidden="true"></i></span>
            <span class="finance-ops-tab-text">{{ __('sales-salary.tab_incentive') }}</span>
        </button>
        @endif
        @if($canViewCompanyPayout ?? false)
        <button type="button"
                class="finance-ops-tab"
                id="tab-company-payout"
                role="tab"
                aria-selected="false"
                aria-controls="tab-company-payout-pane"
                data-bs-target="#tab-company-payout-pane">
            <span class="finance-ops-tab-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
            <span class="finance-ops-tab-text">{{ __('company-payout.tab') }}</span>
        </button>
        @endif
    </div>
</div>

<div class="tab-content" id="salesEarningsTabContent">
    <div class="tab-pane fade show active" id="tab-commission-pane" role="tabpanel">
        <div class="glass p-3 mb-4">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label mb-1">{{ __('sales-revenue.filter_date_from') }}</label>
                    <input type="date" id="filterDateFrom" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">{{ __('sales-revenue.filter_date_to') }}</label>
                    <input type="date" id="filterDateTo" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">{{ __('sales-revenue.filter_status') }}</label>
                    <select id="filterCommissionStatus" class="form-select">
                        <option value="">{{ __('sales-revenue.all_status') }}</option>
                        <option value="waiting">{{ __('finance-earnings.status_estimasi') }}</option>
                        <option value="fixed">{{ __('finance-earnings.status_fixed') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">{{ __('barang.filter_partai') }}</label>
                    <select id="filterCommissionPartai" class="form-select select2" data-placeholder="{{ __('barang.filter_all_partai') }}">
                        <option value="">{{ __('barang.filter_all_partai') }}</option>
                        <option value="belum_di_muat">{{ __('barang.partai_not_loaded') }}</option>
                        @foreach(($partaiOptions ?? []) as $partai)
                        <option value="{{ $partai }}">{{ $partai }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-glass w-100" id="btnRefreshSalesRevenue" style="height:42px">
                        <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
                    </button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-accent w-100" id="btnExportSalesRevenue" style="height:42px">
                        <i class="bi bi-file-earmark-excel me-1"></i>{{ __('sales-revenue.export_excel') }}
                    </button>
                </div>
            </div>
            <p class="small mb-0 mt-3" style="color:var(--text-muted)">
                <i class="bi bi-info-circle me-1"></i>{{ __('sales-salary.commission_tab_hint') }}
            </p>
        </div>
        <div class="glass p-4 sales-revenue-table-wrap">
            <div id="salesRevenueTableLoading" class="finance-table-loading d-none" aria-hidden="true">
                <div class="spinner-border spinner-border-sm text-accent" role="status"></div>
                <span class="small" style="color:var(--text-muted)">{{ __('common.loading') }}</span>
            </div>
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-wallet2 me-2 text-accent"></i>{{ __('finance-earnings.tab_sales') }}</h6>
            <div class="table-responsive">
                <table id="salesRevenueTable" class="table-glass w-100">
                    <thead>
                        <tr>
                            <th>{{ __('sales-revenue.col_no_index') }}</th>
                            <th>{{ __('sales-revenue.col_date') }}</th>
                            <th>{{ __('sales-revenue.col_marking') }}</th>
                            <th>{{ __('finance.col_partai') }}</th>
                            @unless($isSales)
                            <th>{{ __('sales-revenue.col_sales') }}</th>
                            @endunless
                            <th>{{ __('finance-earnings.col_total_cbm') }}</th>
                            <th>{{ __('finance-earnings.col_omset') }}</th>
                            <th>%</th>
                            <th>{{ __('sales-revenue.col_estimasi') }}</th>
                            <th>{{ __('sales-revenue.col_fix') }}</th>
                            @if($isSupervisor || !$isSales)
                            <th>{{ __('finance-earnings.col_supervisor') }}</th>
                            @endif
                            <th>{{ __('sales-salary.col_invoice_status') }}</th>
                            <th>{{ __('sales-salary.col_commission_status') }}</th>
                            <th>{{ __('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-salary-pane" role="tabpanel">
        @include('finance.partials.payout-monthly', ['isSales' => $isSales, 'canUploadSalaryProof' => $canUploadSalaryProof])
    </div>
    @if($canViewIncentive)
    <div class="tab-pane fade" id="tab-incentive-pane" role="tabpanel">
        @include('finance.partials.incentive-monthly', ['isSales' => $isSales, 'incentivePeriod' => $incentivePeriod ?? now()->format('Y-m')])
    </div>
    @endif
    @if($canViewCompanyPayout ?? false)
    <div class="tab-pane fade" id="tab-company-payout-pane" role="tabpanel">
        @include('finance.partials.company-payout-tab', ['canManageCompanyPayout' => $canManageCompanyPayout ?? false])
    </div>
    @endif
</div>

@include('finance.partials.salary-modals', ['canUploadSalaryProof' => $canUploadSalaryProof])
@include('finance.partials.commission-detail-modals', ['canUploadSalaryProof' => $canUploadSalaryProof])
@if($canViewIncentive)
@include('finance.partials.incentive-modals')
@endif
@if($canViewCompanyPayout ?? false)
@include('finance.partials.company-payout-modals', ['canManageCompanyPayout' => $canManageCompanyPayout ?? false])
@endif
@endsection

@push('scripts')
@php
    // sales-salary last among payout groups: invoice labels (col_invoice_status / payment_*) must win over company-payout.
    $salesRevI18n = \App\Support\JsTranslations::merge(['sales-revenue', 'sales-incentive', 'finance-earnings', 'company-payout', 'sales-salary', 'barang', 'common', 'swal']);
@endphp
<script>Object.assign(window.__t || {}, @json($salesRevI18n));</script>
<script>
    window._salesRevenuePage = 'sales';
    window._salesRevenueIsSales = @json($isSales);
    window._salesRevenueIsSupervisor = @json($isSupervisor);
    window._salesRevenueShowSupervisorCol = @json($isSupervisor || !$isSales);
    window._salesRevenueRates = @json($commissionRates);
    window._canUploadSalaryProof = @json($canUploadSalaryProof);
    window._canManageCompanyPayout = @json($canManageCompanyPayout ?? false);
    window._payoutDefaultPeriod = @json(now()->format('Y-m'));
</script>
<script src="{{ asset('Custom_js/Sales/Revenue.js') }}?v={{ time() }}" defer></script>
<script src="{{ asset('Custom_js/Sales/Salary.js') }}?v={{ time() }}" defer></script>
@if($canViewIncentive)
<script src="{{ asset('Custom_js/Sales/Incentive.js') }}?v={{ time() }}" defer></script>
@endif
@if($canViewCompanyPayout ?? false)
<script src="{{ asset('Custom_js/Sales/CompanyPayout.js') }}?v={{ time() }}" defer></script>
@endif
<script src="{{ asset('Custom_js/Sales/EarningsTabs.js') }}?v={{ time() }}" defer></script>
@endpush
