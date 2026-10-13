@extends('layouts.app')
@section('title', __('tracking.customer_title'))

@section('content')
@if(!empty($portalMarking) || !empty($portalMarkings))
@include('_partials.portal-markings')
@endif

@include('tracking._partials.barang-tracking-stats', ['stats' => $stats])

<div class="glass p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end" id="customerTrackingFilter">
        <div class="col-md-4">
            <label class="form-label">{{ __('common.search') }}</label>
            <input type="text" name="search" class="form-control" placeholder="{{ __('tracking.search_placeholder_customer') }}" value="{{ request('search') }}" autocomplete="off">
        </div>
        <div class="col-md-3">
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
            <button type="button" class="btn btn-accent w-100" id="customerTrackingSearchBtn"><i class="bi bi-search"></i></button>
        </div>
        <div class="col-md-3">
            <label class="form-label d-none d-md-block">&nbsp;</label>
            <button type="button" class="btn btn-glass w-100" id="customerTrackingResetBtn">{{ __('common.reset') }}</button>
        </div>
    </form>
</div>

<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)"><i class="bi bi-radar me-2 text-accent"></i>{{ __('tracking.customer_title') }}</h6>
        <span class="small" style="color:var(--text-muted)" id="trackingResultCount">{{ $barangs->total() }} items</span>
    </div>
    <div class="table-responsive tracking-table-wrap" id="trackingTableWrap">
        <table class="table-glass" id="customerTrackingTable">
            <thead>
                <tr>
                    <th>{{ __('tracking.col_no_index') }}</th>
                    <th class="tracking-col-resi">{{ __('tracking.col_no_resi_pengiriman') }}</th>
                    <th>{{ __('tracking.col_marking') }}</th>
                    <th>{{ __('tracking.col_nama_barang') }}</th>
                    <th>{{ __('barang.col_tanggal_masuk') }}</th>
                    <th>{{ __('tracking.col_ctns') }}</th>
                    <th>{{ __('barang.col_cbm') }}</th>
                    <th>{{ __('tracking.col_container') }}</th>
                    <th>{{ __('tracking.col_destination') }}</th>
                    <th>{{ __('barang.col_status') }}</th>
                    <th>{{ __('tracking.col_last_location') }}</th>
                    <th>{{ __('tracking.col_notes') }}</th>
                    <th class="text-center" style="width:7.5rem">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody id="trackingTableBody">
                @include('tracking._partials.customer-rows', ['barangs' => $barangs])
            </tbody>
        </table>
    </div>
    <div class="mt-3" id="trackingPagination">{{ $barangs->withQueryString()->links('vendor.pagination.bootstrap-5') }}</div>
</div>
@endsection

@push('styles')
<style>
#customerTrackingTable .customer-track-row {
    cursor: pointer;
}
#customerTrackingTable .customer-track-row:hover {
    background: rgba(var(--accent-rgb), .06);
}
#customerTrackingTable th.tracking-col-resi,
#customerTrackingTable td.tracking-col-resi {
    min-width: 100px;
    max-width: 140px;
    vertical-align: top;
}
.tracking-resi-stack {
    font-size: .78rem;
}
.barang-resi-stack {
    display: flex;
    flex-direction: column;
    gap: 3px;
    line-height: 1.3;
}
.barang-resi-stack .barang-resi-line {
    word-break: break-all;
    color: var(--text-primary);
}
.tracking-resi-stack .barang-resi-line {
    word-break: break-all;
}
</style>
@endpush

@push('scripts')
@include('tracking._partials.ajax-filter-scripts')
<script>
    registerTrackingAjaxFilter({
        formSelector: '#customerTrackingFilter',
        url: @json(route('tracking.index')),
        statsSelector: '#trackingStats',
        tableBodySelector: '#trackingTableBody',
        paginationSelector: '#trackingPagination',
        countSelector: '#trackingResultCount',
        resetSelector: '#customerTrackingResetBtn',
        searchButtonSelector: '#customerTrackingSearchBtn',
    });
</script>
@endpush
