@extends('layouts.app')
@section('title', __('delivery_trip.detail_title'))

@section('topbar-actions')
@if($canPdf)
<a href="{{ route('delivery-trip.pdf', $trip) }}" class="btn btn-glass btn-sm" target="_blank">
    <i class="bi bi-file-earmark-pdf me-1"></i>{{ __('delivery_trip.download_pdf') }}
</a>
@endif
<a href="{{ route('delivery-trip.index') }}" class="btn btn-glass btn-sm">
    <i class="bi bi-arrow-left me-1"></i>{{ __('common.back') }}
</a>
@endsection

@section('content')
<div class="row g-3">
    <div class="col-lg-5">
        <div class="glass p-4 mb-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h6 class="fw-700 mb-1">{{ $trip->trip_no }}</h6>
                    <span class="badge-glass {{ $trip->statusBadgeClass() }}">{{ \App\Models\DeliveryTrip::statusLabel($trip->status) }}</span>
                </div>
            </div>
            <table class="table table-sm table-borderless mb-0 small">
                <tr><td class="text-muted-glass">{{ __('delivery_trip.truck_plate') }}</td><td class="fw-600">{{ $trip->truck_plate }}</td></tr>
                <tr><td class="text-muted-glass">{{ __('delivery_trip.driver_name') }}</td><td>{{ $trip->driver_name }} @if($trip->driver_phone)<span class="text-muted-glass">· {{ $trip->driver_phone }}</span>@endif</td></tr>
                <tr><td class="text-muted-glass">{{ __('delivery_trip.destination') }}</td><td>{{ $trip->destination ?: '—' }}</td></tr>
                <tr><td class="text-muted-glass">{{ __('delivery_trip.container') }}</td><td>{{ $trip->container?->partai ?? '—' }}</td></tr>
                <tr><td class="text-muted-glass">{{ __('delivery_trip.timeline') }}</td>
                    <td>
                        @if($trip->departed_at)<div><i class="bi bi-box-arrow-right me-1"></i>{{ $trip->departed_at->format('d M Y H:i') }}</div>@endif
                        @if($trip->arrived_at)<div><i class="bi bi-geo-alt me-1"></i>{{ $trip->arrived_at->format('d M Y H:i') }}</div>@endif
                    </td>
                </tr>
            </table>
            @if($trip->status === \App\Models\DeliveryTrip::STATUS_ARRIVED)
            @can('manage delivery trip')
            <button type="button" class="btn btn-accent btn-sm w-100 mt-3" id="btnCompleteTrip">
                <i class="bi bi-check-circle me-1"></i>{{ __('delivery_trip.mark_complete') }}
            </button>
            @endcan
            @endif
        </div>

        <div class="glass p-4">
            <h6 class="fw-700 mb-2">{{ __('delivery_trip.items') }} ({{ $trip->barangs->count() }})</h6>
            <div class="table-responsive">
                <table class="table-glass table-sm w-100">
                    <thead><tr><th>No. Urut</th><th>Marking</th><th>CTNS</th><th>CBM</th></tr></thead>
                    <tbody>
                        @foreach($trip->barangs as $b)
                        <tr>
                            <td>{{ $b->no_index }}</td>
                            <td>{{ $b->marking }}</td>
                            <td>{{ $b->ctns }}</td>
                            <td>{{ \App\Support\CbmFormat::format((float) $b->total_cbm) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        @if($canConfirmDeparture)
        <div class="glass p-4 mb-3">
            <h6 class="fw-700 text-warning mb-3"><i class="bi bi-camera-video me-1"></i>{{ __('delivery_trip.confirm_departure') }}</h6>
            <form id="formDepart" enctype="multipart/form-data">
                <div class="mb-2">
                    <label class="form-label">{{ __('delivery_trip.photos') }} <span class="text-danger">*</span></label>
                    <input type="file" name="photos[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple required>
                    <div class="form-text">{{ __('delivery_trip.photos_hint') }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('delivery_trip.videos') }} <span class="text-danger">*</span></label>
                    <input type="file" name="videos[]" class="form-control" accept="video/mp4,video/quicktime,video/webm,video/*" multiple required>
                    <div class="form-text">{{ __('delivery_trip.videos_hint') }}</div>
                </div>
                <button type="submit" class="btn btn-accent w-100" id="btnDepart">
                    <span class="spinner-border spinner-border-sm d-none me-1"></span>
                    <i class="bi bi-send me-1"></i>{{ __('delivery_trip.confirm_departure') }}
                </button>
            </form>
        </div>
        @endif

        @if($canConfirmArrival)
        <div class="glass p-4 mb-3">
            <h6 class="fw-700 text-success mb-3"><i class="bi bi-pin-map me-1"></i>{{ __('delivery_trip.confirm_arrival') }}</h6>
            <form id="formArrive" enctype="multipart/form-data">
                <div class="mb-2">
                    <label class="form-label">{{ __('delivery_trip.photos') }} <span class="text-danger">*</span></label>
                    <input type="file" name="photos[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple required>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('delivery_trip.videos') }} <span class="text-danger">*</span></label>
                    <input type="file" name="videos[]" class="form-control" accept="video/mp4,video/quicktime,video/webm,video/*" multiple required>
                </div>
                <button type="submit" class="btn btn-accent w-100" id="btnArrive">
                    <span class="spinner-border spinner-border-sm d-none me-1"></span>
                    <i class="bi bi-check-lg me-1"></i>{{ __('delivery_trip.confirm_arrival') }}
                </button>
            </form>
        </div>
        @endif

        @include('delivery-trip._media-section', [
            'title' => __('delivery_trip.departure_section'),
            'photos' => $departurePhotos,
            'videos' => $departureVideos,
        ])
        @include('delivery-trip._media-section', [
            'title' => __('delivery_trip.arrival_section'),
            'photos' => $arrivalPhotos,
            'videos' => $arrivalVideos,
        ])
    </div>
</div>
@endsection

@push('scripts')
@php $dtI18n = \App\Support\JsTranslations::merge(['delivery_trip', 'common', 'swal']); @endphp
<script>
    window.deliveryTripId = @json($trip->id);
    window.deliveryTripDepartUrl = @json(route('delivery-trip.confirm-departure', $trip));
    window.deliveryTripArriveUrl = @json(route('delivery-trip.confirm-arrival', $trip));
    window.deliveryTripCompleteUrl = @json(route('delivery-trip.complete', $trip));
    Object.assign(window.__t || {}, @json($dtI18n));
</script>
<script src="{{ asset('Custom_js/DeliveryTrip/Show.js') }}?v={{ time() }}" defer></script>
@endpush
