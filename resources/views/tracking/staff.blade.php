@extends('layouts.app')
@section('title', __('tracking.staff_title'))

@section('content')
@include('tracking._partials.staff-container-stats', ['stats' => $stats])

{{-- Search & Filters --}}
<div class="glass p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end" id="staffContainerTrackingFilter">
        <div class="col-md-3">
            <label class="form-label">{{ __('common.search') }}</label>
            <input type="text" name="search" class="form-control" placeholder="{{ __('tracking.search_placeholder_staff') }}" value="{{ request('search') }}" autocomplete="off">
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('barang.col_status') }}</label>
            <select name="status" class="form-select select2" data-placeholder="{{ __('tracking.filter_all_status') }}">
                <option value="">{{ __('tracking.filter_all') }}</option>
                @foreach(\App\Models\Container::TRACKING_STATUSES as $s)
                <option value="{{ $s }}" @selected(request('status')===$s)>{{ \App\Models\Container::trackingStatusLabel($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('tracking.filter_destination') }}</label>
            <select name="destination" class="form-select select2" id="destinationFilter" data-placeholder="{{ __('tracking.filter_all_destinations') }}" data-selected="{{ request('destination') }}">
                <option value="">{{ __('tracking.filter_all') }}</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('tracking.filter_date_from') }}</label>
            <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('tracking.filter_date_to') }}</label>
            <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-accent w-100" id="staffContainerSearchBtn"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)"><i class="bi bi-radar me-2 text-accent"></i>{{ __('tracking.staff_title') }}</h6>
        <span class="small" style="color:var(--text-muted)" id="trackingResultCount">{{ __('tracking.containers_count', ['count' => $containers->total()]) }}</span>
    </div>
    <div class="table-responsive tracking-table-wrap" id="trackingTableWrap">
        <table class="table-glass">
            <thead>
                <tr>
                    <th>{{ __('tracking.col_container_info') }}</th>
                    <th class="col-mark-customer">{{ __('barang.col_marking_customer') }}</th>
                    <th>{{ __('tracking.col_shipments') }}</th>
                    <th>{{ __('tracking.col_ctns') }} / {{ __('barang.col_cbm') }}</th>
                    <th>{{ __('tracking.col_nama_barang') }}</th>
                    <th>{{ __('barang.col_status') }}</th>
                    <th>{{ __('tracking.col_last_location') }}</th>
                    <th>{{ __('tracking.col_notes') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="trackingTableBody">
                @include('tracking._partials.staff-container-rows', ['containers' => $containers])
            </tbody>
        </table>
    </div>
    <div class="mt-3" id="trackingPagination">{{ $containers->withQueryString()->links('vendor.pagination.bootstrap-5') }}</div>
</div>

@include('tracking._partials.modals')
@endsection

@push('styles')
<style>
.tracking-action-group {
    justify-content: flex-end;
    white-space: nowrap;
}
.tracking-action-btn {
    width: 38px;
    height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.sj-bukti-popup .swal2-html-container {
    max-height: 70vh;
    overflow-y: auto;
    text-align: left;
}
.sj-bukti-phase {
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    padding: 14px;
    background: rgba(255, 255, 255, 0.02);
}
.sj-bukti-departure {
    border-left: 3px solid #f59e0b;
}
.sj-bukti-arrival {
    border-left: 3px solid #22c55e;
}
.sj-bukti-preview-area {
    min-height: 4px;
}
.sj-bukti-preview-thumb img {
    width: 72px;
    height: 72px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--glass-border);
    display: block;
}
.sj-bukti-preview-video {
    padding: 6px 8px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--glass-border);
}
.tracking-expand-head {
    display: flex;
    align-items: flex-start;
    gap: .35rem;
    max-width: 11.5rem;
}
.tracking-container-row.is-expanded .tracking-expand-head {
    max-width: none;
}
.tracking-expand-cue {
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
    margin-top: 1px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(var(--primary-rgb), .12);
    color: var(--accent);
    font-size: .65rem;
    line-height: 1;
    transition: transform .15s ease, background .15s ease;
}
.tracking-container-row.is-expanded .tracking-expand-cue {
    transform: rotate(180deg);
    background: rgba(var(--primary-rgb), .2);
}
.tracking-expand-hit {
    cursor: pointer;
}
.tracking-expand-hit:hover .tracking-expand-cue {
    background: rgba(var(--primary-rgb), .22);
}
.tracking-expand-text {
    min-width: 0;
    flex: 1 1 auto;
    line-height: 1.3;
    word-break: break-word;
}
.tracking-expand-text.is-collapsed {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 9.5rem;
}
.tracking-nama-barang-cell .tracking-expand-text.is-collapsed {
    max-width: 11rem;
}
.tracking-container-row.has-expandable .mark-cell {
    max-width: 12rem;
}
.tracking-container-row.is-expanded.has-expandable .mark-cell {
    max-width: none;
}
</style>
@endpush

@push('scripts')
@include('tracking._partials.surat-jalan-scripts')
@include('tracking._partials.scripts')
@include('tracking._partials.ajax-filter-scripts')
<script>
    window.TRACKING_STAFF_SHOW_URL = @json(route('tracking.staff.show', ['container' => '__CONTAINER_ID__']));
    window.TRACKING_STAFF_UPDATE_URL = @json(route('tracking.staff.update', ['container' => '__CONTAINER_ID__']));
    window.TRACKING_STAFF_TIMELINE_UPDATE_URL = @json(route('tracking.staff.timeline.update', ['container' => '__CONTAINER_ID__', 'containerTrackingStatus' => '__ENTRY_ID__']));
    window.TRACKING_STAFF_TIMELINE_DELETE_URL = @json(route('tracking.staff.timeline.delete', ['container' => '__CONTAINER_ID__', 'containerTrackingStatus' => '__ENTRY_ID__']));
    window.TRACKING_CAN_MANAGE_TIMELINE = @json(auth()->user()?->can('update tracking status'));
    window.TRACKING_STAFF_DELIVERY_TRIP_SUMMARY_URL = @json(route('tracking.staff.delivery-trip.summary', ['container' => '__CONTAINER_ID__']));
    window.TRACKING_STAFF_DELIVERY_TRIP_UPSERT_URL = @json(route('tracking.staff.delivery-trip.upsert', ['container' => '__CONTAINER_ID__']));
    window.DELIVERY_TRIP_PDF_URL = @json(route('delivery-trip.pdf', ['deliveryTrip' => '__TRIP_ID__']));
    window.DELIVERY_TRIP_DEPART_URL = @json(route('delivery-trip.confirm-departure', ['deliveryTrip' => '__TRIP_ID__']));
    window.DELIVERY_TRIP_ARRIVE_URL = @json(route('delivery-trip.confirm-arrival', ['deliveryTrip' => '__TRIP_ID__']));

    registerTrackingAjaxFilter({
        formSelector: '#staffContainerTrackingFilter',
        url: @json(route('tracking.staff')),
        statsSelector: '#trackingStats',
        tableBodySelector: '#trackingTableBody',
        paginationSelector: '#trackingPagination',
        countSelector: '#trackingResultCount',
        searchButtonSelector: '#staffContainerSearchBtn',
    });
</script>
@endpush
