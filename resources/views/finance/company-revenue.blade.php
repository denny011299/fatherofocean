@extends('layouts.app')
@section('title', __('finance-earnings.tab_company'))

@section('content')
<div class="bento-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="glass stat-card">
        <div class="stat-label">{{ __('finance-earnings.estimasi_company') }}</div>
        <div class="stat-value text-warning" id="statEstimasiCompany">Rp {{ number_format($totals['estimasi_company'] ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">{{ __('finance-earnings.fix_company') }}</div>
        <div class="stat-value text-accent" id="statFixCompany">Rp {{ number_format($totals['fix_company'] ?? 0, 0, ',', '.') }}</div>
    </div>
</div>

<div class="row g-2 mb-4 d-none" id="companyCategoryTotalsRow">
    <div class="col-12">
        <div class="glass p-3">
            <h6 class="fw-700 mb-2 small" style="color:var(--text-primary)">
                <i class="bi bi-tags me-2 text-accent"></i>{{ __('finance-earnings.category_totals_title') }}
            </h6>
            <div class="row g-2" id="companyCategoryTotalsCards"></div>
        </div>
    </div>
</div>

<div class="glass p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label mb-1">{{ __('sales-salary.filter_period') }}</label>
            <input type="month" id="filterCompanyPeriod" class="form-control" value="{{ now()->format('Y-m') }}">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">{{ __('finance.col_marketing') }}</label>
            <select id="filterCompanyMarketing" class="form-select select2">
                <option value="">{{ __('finance-earnings.filter_all_marketing') }}</option>
                @foreach($marketingOptions ?? [] as $marketing)
                    <option value="{{ $marketing->id }}">{{ $marketing->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">{{ __('barang.filter_partai') }}</label>
            <select id="filterCompanyPartai" class="form-select select2" data-placeholder="{{ __('barang.filter_all_partai') }}">
                <option value="">{{ __('barang.filter_all_partai') }}</option>
                <option value="belum_di_muat">{{ __('barang.partai_not_loaded') }}</option>
                @foreach($partaiOptions ?? [] as $partai)
                    <option value="{{ $partai }}">{{ $partai }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1 d-block">&nbsp;</label>
            <button type="button" class="btn btn-glass w-100" id="btnCompanyExclude" style="height:42px">
                <i class="bi bi-slash-circle me-1"></i>{{ __('finance-earnings.btn_exclude_marking') }}
                <span class="badge bg-secondary ms-1 d-none" id="excludeMarkingCount">0</span>
            </button>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-glass w-100" id="btnRefreshCompany" style="height:42px">
                <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
            </button>
        </div>
    </div>
</div>

<div class="modal fade" id="modalExcludeMarking" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass">
            <div class="modal-header border-0">
                <h6 class="modal-title fw-700">{{ __('finance-earnings.exclude_marking_title') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted-glass small mb-3">{{ __('finance-earnings.exclude_marking_hint') }}</p>
                <div class="mb-3">
                    <label class="form-label mb-1" for="excludeModalMarketing">{{ __('finance.col_marketing') }}</label>
                    <select id="excludeModalMarketing" class="form-select select2" style="width:100%">
                        <option value="">{{ __('finance-earnings.exclude_pick_marketing') }}</option>
                        @foreach($marketingOptions ?? [] as $marketing)
                            <option value="{{ $marketing->id }}">{{ $marketing->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label mb-1" for="excludeMarkingSelect">{{ __('finance-earnings.exclude_marking_label') }}</label>
                    <select id="excludeMarkingSelect" class="form-select select2" multiple style="width:100%"></select>
                </div>
            </div>
            <div class="modal-footer border-0 py-2">
                <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                <button type="button" class="btn btn-accent btn-sm" id="btnSaveExcludeMarking">
                    <i class="bi bi-check2 me-1"></i>{{ __('common.save') }}
                </button>
            </div>
        </div>
    </div>
</div>

<div class="glass p-4">
    <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
        <i class="bi bi-building me-2 text-accent"></i>{{ __('finance-earnings.tab_company') }}
        <span class="small fw-normal ms-1" style="color:var(--text-muted)" id="companyTablePeriodLabel"></span>
    </h6>
    <div class="table-responsive">
        <table id="companyRevenueTable" class="table-glass w-100">
            <thead>
                <tr>
                    <th>{{ __('sales-revenue.col_no_index') }}</th>
                    <th>{{ __('sales-revenue.col_date') }}</th>
                    <th>{{ __('sales-revenue.col_marking') }}</th>
                    <th>{{ __('finance-earnings.col_kategori') }}</th>
                    <th>{{ __('finance.col_partai') }}</th>
                    <th>{{ __('finance-earnings.col_omset') }}</th>
                    <th>{{ __('finance-earnings.col_company_profit') }}</th>
                    <th>{{ __('finance-earnings.status_estimasi') }}</th>
                    <th>{{ __('finance-earnings.status_fixed') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
@php
    $i18n = \App\Support\JsTranslations::merge(['sales-revenue', 'sales-salary', 'finance-earnings', 'barang', 'common', 'swal']);
@endphp
<script>Object.assign(window.__t || {}, @json($i18n));</script>
<script>window._payoutDefaultPeriod = @json(now()->format('Y-m'));</script>
<script src="{{ asset('Custom_js/Sales/CompanyRevenue.js') }}?v={{ time() }}" defer></script>
@endpush
