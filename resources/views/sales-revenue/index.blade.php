@extends('layouts.app')
@section('title', __('sales-revenue.title'))

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
<div class="bento-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="glass stat-card">
        <i class="bi bi-hourglass-split stat-icon text-warning"></i>
        <div class="stat-label">{{ __('sales-revenue.estimasi') }}</div>
        <div class="stat-value text-warning" id="statEstimasi">Rp {{ number_format($totals['estimasi'], 0, ',', '.') }}</div>
        <div class="stat-change"><i class="bi bi-percent"></i> {{ __('sales-revenue.rate_info', ['rate' => $commissionRates['default_rate']]) }}</div>
    </div>
    <div class="glass stat-card">
        <i class="bi bi-check-circle stat-icon text-accent"></i>
        <div class="stat-label">{{ __('sales-revenue.fix') }}</div>
        <div class="stat-value text-accent" id="statFix">Rp {{ number_format($totals['fix'], 0, ',', '.') }}</div>
        <div class="stat-change up"><i class="bi bi-cash-coin"></i> {{ __('sales-revenue.status_confirmed') }} / {{ __('sales-revenue.status_cair') }}</div>
    </div>
    @if($totals['waiting_count'] > 0)
    <div class="glass stat-card">
        <i class="bi bi-bell stat-icon"></i>
        <div class="stat-label">{{ __('sales-revenue.waiting_confirm') }}</div>
        <div class="stat-value" id="statWaiting">{{ number_format($totals['waiting_count']) }}</div>
        <div class="stat-change down"><i class="bi bi-exclamation-circle"></i> {{ __('sales-revenue.status_waiting') }}</div>
    </div>
    @endif
</div>

<ul class="nav nav-tabs nav-tabs-glass mb-3" id="salesRevenueTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-commission-btn" data-bs-toggle="tab" data-bs-target="#tab-commission-pane" type="button" role="tab">
            <i class="bi bi-wallet2 me-1"></i>{{ __('sales-salary.tab_commission') }}
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-salary" data-bs-toggle="tab" data-bs-target="#tab-salary-pane" type="button" role="tab">
            <i class="bi bi-cash-stack me-1"></i>{{ $isSales ? __('sales-salary.tab_salary_history') : __('sales-salary.tab_salary') }}
        </button>
    </li>
</ul>

<div class="tab-content">
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
                        <option value="waiting">{{ __('sales-revenue.status_waiting') }}</option>
                        <option value="confirmed">{{ __('sales-revenue.status_confirmed') }}</option>
                        <option value="cair">{{ __('sales-revenue.status_cair') }}</option>
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
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)"><i class="bi bi-wallet2 me-2 text-accent"></i>{{ __('sales-revenue.title') }}</h6>
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
                            <th>{{ __('sales-revenue.col_cbm') }}</th>
                            <th>{{ __('sales-revenue.col_base') }}</th>
                            <th>{{ __('sales-revenue.col_estimasi') }}</th>
                            <th>{{ __('sales-revenue.col_fix') }}</th>
                            <th>{{ __('sales-revenue.col_status') }}</th>
                            <th>{{ __('sales-revenue.col_invoice') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-salary-pane" role="tabpanel">
        <div class="glass p-3 mb-4">
            <p class="small mb-3" style="color:var(--text-muted)">{{ __('sales-salary.hint') }}</p>
            <div class="row g-2 align-items-end">
                @unless($isSales)
                <div class="col-md-4">
                    <label class="form-label mb-1">{{ __('sales-salary.filter_sales') }}</label>
                    <select id="filterSalarySales" class="form-select select2" data-placeholder="{{ __('sales-salary.all_sales') }}">
                        <option value="">{{ __('sales-salary.all_sales') }}</option>
                    </select>
                </div>
                @endunless
                <div class="col-md-2">
                    <button type="button" class="btn btn-glass w-100" id="btnRefreshSalary" style="height:42px">
                        <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
                <i class="bi bi-cash-stack me-2 text-accent"></i>
                {{ $isSales ? __('sales-salary.tab_salary_history') : __('sales-salary.title') }}
            </h6>
            <div class="table-responsive">
                <table id="salesSalaryTable" class="table-glass w-100">
                    <thead>
                        <tr>
                            <th>{{ __('sales-salary.col_period') }}</th>
                            @unless($isSales)
                            <th>{{ __('sales-salary.col_sales') }}</th>
                            @endunless
                            <th>{{ __('sales-salary.col_items') }}</th>
                            <th>{{ __('sales-salary.col_total') }}</th>
                            <th>{{ __('sales-salary.col_proof') }}</th>
                            <th>{{ __('sales-salary.col_uploaded') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalConfirmCommission" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass">
            <div class="modal-header border-0">
                <h5 class="modal-title">{{ __('sales-revenue.modal_confirm_title') }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted-glass mb-3" id="confirmCommissionHint"></p>
                <div class="mb-3">
                    <label class="form-label">{{ __('sales-revenue.modal_amount') }}</label>
                    <input type="number" class="form-control" id="confirmAmount" min="0" step="1">
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('sales-revenue.modal_note') }}</label>
                    <textarea class="form-control" id="confirmNote" rows="2" maxlength="500"></textarea>
                </div>
                @can('mark sales commission cair')
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirmMarkCair" checked>
                    <label class="form-check-label" for="confirmMarkCair">{{ __('sales-revenue.modal_mark_cair') }}</label>
                </div>
                @endcan
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                <button type="button" class="btn btn-accent" id="btnSaveConfirmCommission">{{ __('common.save') }}</button>
            </div>
        </div>
    </div>
</div>

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
                        <input type="file" name="proof_file" id="salaryProofFile" class="form-control" accept="image/*,.pdf" required>
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
@endsection

@push('scripts')
@php
    $salesRevI18n = \App\Support\JsTranslations::merge(['sales-revenue', 'sales-salary', 'common', 'swal', 'barang']);
@endphp
<script>Object.assign(window.__t || {}, @json($salesRevI18n));</script>
<script>
    window._salesRevenueCanConfirm = @json($canConfirm);
    window._salesRevenueCanCair = @json($canCair);
    window._salesRevenueIsSales = @json($isSales);
    window._salesRevenueRates = @json($commissionRates);
    window._canUploadSalaryProof = @json($canUploadSalaryProof);
</script>
<script src="{{ asset('Custom_js/Sales/Revenue.js') }}?v={{ time() }}" defer></script>
<script src="{{ asset('Custom_js/Sales/Salary.js') }}?v={{ time() }}" defer></script>
@endpush
