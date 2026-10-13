@extends('layouts.app')
@section('title', __('delivery_trip.title'))

@section('topbar-actions')
@can('create delivery trip')
<a href="{{ route('delivery-trip.create') }}" class="btn btn-accent btn-sm">
    <i class="bi bi-plus-lg me-1"></i>{{ __('delivery_trip.new') }}
</a>
@endcan
@endsection

@section('content')
<div class="glass p-4">
    <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
        <i class="bi bi-signpost-split me-2 text-accent"></i>{{ __('delivery_trip.list_title') }}
    </h6>
    <div class="filter-bar p-3 mb-3" style="border-bottom:1px solid var(--glass-border)">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <input type="text" id="filterSearch" class="form-control" placeholder="{{ __('container.search_placeholder') }}">
            </div>
            <div class="col-md-3">
                <select id="filterStatus" class="form-select">
                    <option value="">{{ __('common.all_status') }}</option>
                    <option value="ready">{{ __('delivery_trip.status_ready') }}</option>
                    <option value="departed">{{ __('delivery_trip.status_departed') }}</option>
                    <option value="arrived">{{ __('delivery_trip.status_arrived') }}</option>
                    <option value="completed">{{ __('delivery_trip.status_completed') }}</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-glass w-100" id="btnResetFilter">{{ __('common.reset') }}</button>
            </div>
        </div>
    </div>
    <table class="table-glass w-100" id="deliveryTripTable">
        <thead>
            <tr>
                <th>{{ __('delivery_trip.trip_no') }}</th>
                <th>{{ __('delivery_trip.truck_plate') }}</th>
                <th>{{ __('delivery_trip.driver_name') }}</th>
                <th>{{ __('delivery_trip.container') }}</th>
                <th>{{ __('delivery_trip.items') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
@endsection

@push('scripts')
@php $dtI18n = \App\Support\JsTranslations::merge(['delivery_trip', 'common', 'container']); @endphp
<script>Object.assign(window.__t || {}, @json($dtI18n));</script>
<script src="{{ asset('Custom_js/DeliveryTrip/Index.js') }}?v={{ time() }}" defer></script>
@endpush
