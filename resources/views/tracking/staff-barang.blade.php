@extends('layouts.app')
@section('title', __('tracking.staff_shipment_title'))

@section('content')
@if(!empty($teamScoped))
<div class="alert alert-glass mb-4 py-2 px-3 small" style="color:var(--text-muted)">
    <i class="bi bi-people me-1"></i>{{ __('tracking.staff_team_scope_hint') }}
</div>
@endif

@include('tracking._partials.barang-tracking-stats', ['stats' => $stats])

<div class="glass p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end" id="staffTrackingFilter">
        <div class="col-md-3">
            <label class="form-label">{{ __('tracking.filter_no_resi') }}</label>
            <input type="text" name="no_resi" class="form-control" placeholder="{{ __('tracking.filter_no_resi_placeholder') }}" value="{{ request('no_resi') }}" autocomplete="off">
        </div>
        <div class="col-md-3">
            <label class="form-label">{{ __('common.search') }}</label>
            <input type="text" name="search" class="form-control" placeholder="{{ __('tracking.search_placeholder_staff_shipment') }}" value="{{ request('search') }}" autocomplete="off">
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('barang.col_status') }}</label>
            <select name="status" class="form-select select2" data-placeholder="{{ __('tracking.filter_all_status') }}">
                <option value="">{{ __('tracking.filter_all_status') }}</option>
                @foreach(\App\Models\Barang::SHIPMENT_STATUSES as $s)
                <option value="{{ $s }}" @selected(request('status')===$s)>{{ __('barang.status_' . $s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label d-none d-md-block">&nbsp;</label>
            <button type="button" class="btn btn-accent w-100" id="staffTrackingSearchBtn"><i class="bi bi-search"></i></button>
        </div>
        <div class="col-md-2">
            <label class="form-label d-none d-md-block">&nbsp;</label>
            <button type="button" class="btn btn-glass w-100" id="staffTrackingResetBtn">{{ __('common.reset') }}</button>
        </div>
    </form>
</div>

<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)"><i class="bi bi-radar me-2 text-accent"></i>{{ __('tracking.staff_shipment_title') }}</h6>
        <span class="small" style="color:var(--text-muted)" id="trackingResultCount">{{ __('tracking.items_count', ['count' => $barangs->total()]) }}</span>
    </div>
    <div class="table-responsive tracking-table-wrap" id="trackingTableWrap">
        <table class="table-glass" id="staffTrackingTable">
            <thead>
                <tr>
                    <th>{{ __('tracking.col_no_index') }}</th>
                    <th>{{ __('tracking.col_marking') }}</th>
                    <th>{{ __('tracking.col_nama_barang') }}</th>
                    <th>{{ __('tracking.col_marketing') }}</th>
                    <th>{{ __('tracking.col_ctns') }}</th>
                    <th>{{ __('barang.col_cbm') }}</th>
                    <th>{{ __('tracking.col_partai') }}</th>
                    <th>{{ __('barang.col_status') }}</th>
                    <th>{{ __('tracking.col_last_location') }}</th>
                    <th>{{ __('tracking.col_notes') }}</th>
                    <th class="text-center" style="width:3.5rem"></th>
                </tr>
            </thead>
            <tbody id="trackingTableBody">
                @include('tracking._partials.staff-barang-rows', ['barangs' => $barangs])
            </tbody>
        </table>
    </div>
    <div class="mt-3" id="trackingPagination">{{ $barangs->withQueryString()->links('vendor.pagination.bootstrap-5') }}</div>
</div>
@endsection

@push('styles')
<style>
#staffTrackingTable .staff-track-row {
    cursor: pointer;
}
#staffTrackingTable .staff-track-row:hover {
    background: rgba(var(--accent-rgb), .06);
}
</style>
@endpush

@push('scripts')
@include('tracking._partials.ajax-filter-scripts')
<script>
    registerTrackingAjaxFilter({
        formSelector: '#staffTrackingFilter',
        url: @json(route('tracking.staff')),
        statsSelector: '#trackingStats',
        tableBodySelector: '#trackingTableBody',
        paginationSelector: '#trackingPagination',
        countSelector: '#trackingResultCount',
        resetSelector: '#staffTrackingResetBtn',
        searchButtonSelector: '#staffTrackingSearchBtn',
    });
</script>
@endpush
