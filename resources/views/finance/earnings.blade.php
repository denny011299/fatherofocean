@extends('layouts.app')
@section('title', __('finance-earnings.title'))

@push('styles')
<style>
    .nav-tabs-glass .nav-link {
        color: var(--text-muted);
        border: 1px solid transparent;
        border-radius: var(--radius-xl) var(--radius-xl) 0 0;
    }
    .nav-tabs-glass .nav-link.active {
        color: var(--text-primary);
        background: var(--glass-bg);
        border-color: var(--glass-border);
        border-bottom-color: transparent;
    }
</style>
@endpush

@section('content')
<p class="small mb-3" style="color:var(--text-muted)">
    <i class="bi bi-info-circle me-1"></i>{{ __('finance-earnings.settle_rule') }}
    {{ __('finance-earnings.company_fixed_hint', ['amount' => 'Rp '.number_format($companyRevenueFixed, 0, ',', '.')]) }}
</p>

<div class="bento-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="glass stat-card">
        <div class="stat-label">{{ __('finance-earnings.estimasi_sales') }}</div>
        <div class="stat-value text-warning" id="statEstimasi">Rp {{ number_format($totals['estimasi'], 0, ',', '.') }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">{{ __('finance-earnings.fix_sales') }}</div>
        <div class="stat-value text-accent" id="statFix">Rp {{ number_format($totals['fix'], 0, ',', '.') }}</div>
    </div>
    @unless($isSales)
    <div class="glass stat-card">
        <div class="stat-label">{{ __('finance-earnings.estimasi_supervisor') }}</div>
        <div class="stat-value text-warning" id="statEstimasiSupervisor">Rp {{ number_format($totals['estimasi_supervisor'] ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">{{ __('finance-earnings.fix_supervisor') }}</div>
        <div class="stat-value text-accent" id="statFixSupervisor">Rp {{ number_format($totals['fix_supervisor'] ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">{{ __('finance-earnings.estimasi_company') }}</div>
        <div class="stat-value text-warning" id="statEstimasiCompany">Rp {{ number_format($totals['estimasi_company'] ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">{{ __('finance-earnings.fix_company') }}</div>
        <div class="stat-value text-accent" id="statFixCompany">Rp {{ number_format($totals['fix_company'] ?? 0, 0, ',', '.') }}</div>
    </div>
    @endunless
</div>

<ul class="nav nav-tabs nav-tabs-glass mb-3" id="earningsTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-sales-pane" type="button" role="tab">
            <i class="bi bi-wallet2 me-1"></i>{{ __('finance-earnings.tab_sales') }}
        </button>
    </li>
    @can('view sales revenue')
    @unless($isSales)
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-company-pane" type="button" role="tab">
            <i class="bi bi-building me-1"></i>{{ __('finance-earnings.tab_company') }}
        </button>
    </li>
    @endunless
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-salary-pane" type="button" role="tab">
            <i class="bi bi-cash-stack me-1"></i>{{ $isSales ? __('sales-salary.tab_salary_history') : __('sales-salary.tab_salary') }}
        </button>
    </li>
    @endcan
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-sales-pane" role="tabpanel">
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
        </div>
        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-wallet2 me-2 text-accent"></i>{{ __('finance-earnings.tab_sales') }}</h6>
            <div class="table-responsive">
                <table id="salesRevenueTable" class="table-glass w-100">
                    <thead>
                        <tr>
                            <th>{{ __('sales-revenue.col_no_index') }}</th>
                            <th>{{ __('sales-revenue.col_date') }}</th>
                            <th>{{ __('sales-revenue.col_marking') }}</th>
                            @unless($isSales)
                            <th>{{ __('sales-revenue.col_sales') }}</th>
                            @endunless
                            <th>{{ __('finance-earnings.col_sales_base') }}</th>
                            <th>%</th>
                            <th>{{ __('sales-revenue.col_estimasi') }}</th>
                            <th>{{ __('sales-revenue.col_fix') }}</th>
                            @if($isSupervisor || !$isSales)
                            <th>{{ __('finance-earnings.col_supervisor') }}</th>
                            @endif
                            <th>{{ __('sales-revenue.col_status') }}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    @unless($isSales)
    <div class="tab-pane fade" id="tab-company-pane" role="tabpanel">
        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-building me-2 text-accent"></i>{{ __('finance-earnings.tab_company') }}</h6>
            <div class="table-responsive">
                <table id="companyRevenueTable" class="table-glass w-100">
                    <thead>
                        <tr>
                            <th>{{ __('sales-revenue.col_no_index') }}</th>
                            <th>{{ __('sales-revenue.col_date') }}</th>
                            <th>{{ __('sales-revenue.col_marking') }}</th>
                            <th>{{ __('finance-earnings.col_omset') }}</th>
                            <th>{{ __('finance-earnings.col_company_profit') }}</th>
                            <th>{{ __('finance-earnings.status_estimasi') }}</th>
                            <th>{{ __('finance-earnings.status_fixed') }}</th>
                            <th>{{ __('sales-revenue.col_status') }}</th>
                            <th>{{ __('sales-revenue.col_invoice') }}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
    @endunless

    <div class="tab-pane fade" id="tab-salary-pane" role="tabpanel">
        @include('finance.partials.salary-tab', ['isSales' => $isSales, 'canUploadSalaryProof' => $canUploadSalaryProof])
    </div>
</div>

@include('finance.partials.salary-modals', ['canUploadSalaryProof' => $canUploadSalaryProof])
@endsection

@push('scripts')
@php
    $salesRevI18n = \App\Support\JsTranslations::merge(['sales-revenue', 'sales-salary', 'finance-earnings', 'common', 'swal', 'barang']);
@endphp
<script>Object.assign(window.__t || {}, @json($salesRevI18n));</script>
<script>
    window._salesRevenueIsSales = @json($isSales);
    window._salesRevenueIsSupervisor = @json($isSupervisor);
    window._salesRevenueShowSupervisorCol = @json($isSupervisor || !$isSales);
    window._salesRevenueRates = @json($commissionRates);
    window._canUploadSalaryProof = @json($canUploadSalaryProof);
</script>
<script src="{{ asset('Custom_js/Sales/Revenue.js') }}?v={{ time() }}" defer></script>
<script src="{{ asset('Custom_js/Sales/Salary.js') }}?v={{ time() }}" defer></script>
@endpush
