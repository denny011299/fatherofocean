@extends('layouts.app')
@section('title', __('delivery_trip.create_title'))

@section('topbar-actions')
<a href="{{ route('delivery-trip.index') }}" class="btn btn-glass btn-sm">
    <i class="bi bi-arrow-left me-1"></i>{{ __('common.back') }}
</a>
@endsection

@section('content')
<form id="deliveryTripForm" class="form-page-with-sticky-footer dt-create-page" enctype="multipart/form-data">
    <div class="glass p-4 mb-3 dt-create-hero">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
            <div>
                <h5 class="fw-800 mb-1" style="color:var(--text-primary)">
                    <i class="bi bi-truck-front-fill me-2 text-accent"></i>{{ __('delivery_trip.create_title') }}
                </h5>
                <p class="small text-muted-glass mb-0">{{ __('delivery_trip.create_subtitle') }}</p>
            </div>
        </div>
        <div class="dt-steps" aria-hidden="true">
            <div class="dt-step dt-step-active" data-step="1">
                <span class="dt-step-num">1</span>
                <span class="dt-step-label">{{ __('delivery_trip.step_truck') }}</span>
            </div>
            <div class="dt-step-line"></div>
            <div class="dt-step" data-step="2">
                <span class="dt-step-num">2</span>
                <span class="dt-step-label">{{ __('delivery_trip.step_items') }}</span>
            </div>
            <div class="dt-step-line"></div>
            <div class="dt-step" data-step="3">
                <span class="dt-step-num">3</span>
                <span class="dt-step-label">{{ __('delivery_trip.step_save') }}</span>
            </div>
        </div>
    </div>

    <div class="row g-3 align-items-start">
        <div class="col-lg-8">
            <section class="glass p-4 mb-3" id="sectionTruck">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="dt-section-badge">1</span>
                    <h6 class="fw-700 mb-0">{{ __('delivery_trip.step_truck') }}</h6>
                </div>
                <p class="small text-muted-glass mb-3">{{ __('delivery_trip.step_truck_hint') }}</p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('delivery_trip.truck_plate') }} <span class="text-danger">*</span></label>
                        <input type="text" name="truck_plate" id="truckPlate" class="form-control" maxlength="30"
                            placeholder="B 1234 XYZ" required autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('delivery_trip.driver_name') }} <span class="text-danger">*</span></label>
                        <input type="text" name="driver_name" id="driverName" class="form-control" maxlength="120"
                            placeholder="Nama lengkap supir" required autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('delivery_trip.driver_phone') }}</label>
                        <input type="tel" name="driver_phone" class="form-control" maxlength="30"
                            placeholder="08xxxxxxxxxx" inputmode="tel">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('delivery_trip.destination') }}</label>
                        <input type="text" name="destination" class="form-control" maxlength="255"
                            placeholder="{{ __('delivery_trip.destination_placeholder') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ __('delivery_trip.notes') }}</label>
                        <input type="text" name="notes" class="form-control" maxlength="500"
                            placeholder="{{ __('delivery_trip.notes_placeholder') }}">
                    </div>
                </div>
            </section>

            <section class="glass p-4" id="sectionItems">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="dt-section-badge">2</span>
                        <h6 class="fw-700 mb-0">{{ __('delivery_trip.step_items') }}</h6>
                    </div>
                    <span class="badge-glass" id="listResultBadge">—</span>
                </div>
                <p class="small text-muted-glass mb-3">{{ __('delivery_trip.step_items_hint') }}</p>

                <div class="dt-toolbar glass-solid p-3 mb-3">
                    <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-start">
                        <div class="flex-grow-1 min-w-0">
                            <label class="form-label mb-2" for="barangSearch">{{ __('delivery_trip.select_items') }}</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0">
                                    <i class="bi bi-search text-muted-glass"></i>
                                </span>
                                <input type="search" id="barangSearch" class="form-control border-start-0"
                                    placeholder="{{ __('delivery_trip.search_barang') }}" autocomplete="off">
                            </div>
                            <div class="form-text">{{ __('delivery_trip.search_barang_hint') }}</div>
                        </div>
                        <div class="d-flex justify-content-lg-end pt-lg-4">
                            <button type="button" class="btn btn-sm btn-glass" id="btnClearSel" disabled>
                                <i class="bi bi-x-lg me-1"></i>{{ __('delivery_trip.clear_selection') }}
                            </button>
                        </div>
                    </div>
                </div>

                <div id="barangListLoading" class="dt-list-state d-none">
                    <div class="spinner-border spinner-border-sm text-accent" role="status"></div>
                    <span class="ms-2 small text-muted-glass">{{ __('delivery_trip.loading_list') }}</span>
                </div>

                <div id="barangListEmpty" class="dt-list-state">
                    <i class="bi bi-inbox dt-empty-icon"></i>
                    <div class="fw-600 mt-2">{{ __('delivery_trip.empty_list_title') }}</div>
                    <div class="small text-muted-glass">{{ __('delivery_trip.empty_list_hint') }}</div>
                </div>

                <div id="barangList" class="row g-2 dt-barang-grid d-none"></div>
            </section>
        </div>

        <div class="col-lg-4">
            <aside class="glass-solid p-4 dt-summary-panel">
                <h6 class="fw-700 mb-3"><i class="bi bi-clipboard-check me-2 text-accent"></i>{{ __('delivery_trip.summary_title') }}</h6>

                <div class="dt-summary-stats mb-3">
                    <div class="dt-stat">
                        <span class="dt-stat-label">{{ __('delivery_trip.summary_items') }}</span>
                        <span class="dt-stat-value" id="summaryItems">0</span>
                    </div>
                    <div class="dt-stat">
                        <span class="dt-stat-label">CTNS</span>
                        <span class="dt-stat-value" id="summaryCtns">0</span>
                    </div>
                    <div class="dt-stat">
                        <span class="dt-stat-label">CBM</span>
                        <span class="dt-stat-value" id="summaryCbm">0</span>
                    </div>
                </div>

                <div class="small text-muted-glass mb-2">{{ __('delivery_trip.selected_preview') }}</div>
                <ul class="dt-selected-chips list-unstyled mb-3" id="selectedChips">
                    <li class="text-muted-glass small">{{ __('delivery_trip.no_selection_yet') }}</li>
                </ul>

                <div class="dt-tip glass p-3">
                    <i class="bi bi-lightbulb text-warning me-1"></i>
                    <span class="small">{{ __('delivery_trip.create_tip') }}</span>
                </div>
            </aside>
        </div>
    </div>

    <div class="form-sticky-footer">
        <div class="form-sticky-footer-inner">
            <div class="dt-footer-hint small text-muted-glass d-none d-md-block" id="footerHint">
                {{ __('delivery_trip.footer_hint_incomplete') }}
            </div>
            <div class="d-flex gap-2 ms-md-auto">
                <a href="{{ route('delivery-trip.index') }}" class="btn btn-glass">{{ __('common.cancel') }}</a>
                <button type="submit" class="btn btn-accent px-4" id="btnSubmitTrip" disabled>
                    <span class="spinner-border spinner-border-sm d-none me-1" id="btnSubmitSpinner"></span>
                    <i class="bi bi-truck me-1" id="btnSubmitIcon"></i>{{ __('delivery_trip.create_submit') }}
                </button>
            </div>
        </div>
    </div>
</form>
@endsection

@push('styles')
<style>
.dt-create-page .dt-steps {
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-wrap: wrap;
}
.dt-step {
    display: flex;
    align-items: center;
    gap: .5rem;
    opacity: .55;
}
.dt-step-active { opacity: 1; }
.dt-step-num {
    width: 1.65rem;
    height: 1.65rem;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .75rem;
    font-weight: 700;
    background: rgba(var(--primary-rgb, 99, 102, 241), .15);
    color: var(--accent, #818cf8);
}
.dt-step-active .dt-step-num {
    background: var(--accent, #818cf8);
    color: #fff;
}
.dt-step-line {
    flex: 1;
    min-width: 1.5rem;
    max-width: 3rem;
    height: 2px;
    background: var(--glass-border);
}
.dt-section-badge {
    width: 1.5rem;
    height: 1.5rem;
    border-radius: .4rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .7rem;
    font-weight: 800;
    background: rgba(var(--primary-rgb, 99, 102, 241), .12);
    color: var(--accent);
}
.dt-summary-panel {
    position: sticky;
    top: 1rem;
}
.dt-summary-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .5rem;
}
.dt-stat {
    text-align: center;
    padding: .65rem .35rem;
    border-radius: .5rem;
    border: 1px solid var(--glass-border);
    background: var(--input-bg, rgba(255,255,255,.04));
}
.dt-stat-label {
    display: block;
    font-size: .65rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-muted);
}
.dt-stat-value {
    display: block;
    font-size: 1.1rem;
    font-weight: 800;
    color: var(--text-primary);
}
.dt-selected-chips li {
    padding: .35rem .5rem;
    margin-bottom: .35rem;
    border-radius: .4rem;
    background: rgba(var(--primary-rgb, 99, 102, 241), .08);
    font-size: .8rem;
    border: 1px solid var(--glass-border);
}
.dt-tip {
    border: 1px dashed rgba(var(--primary-rgb, 99, 102, 241), .25);
}
.dt-list-state {
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--text-muted);
}
.dt-empty-icon { font-size: 2.5rem; opacity: .45; }
.dt-barang-card {
    cursor: pointer;
    border: 2px solid transparent;
    border-radius: .65rem;
    padding: .75rem;
    transition: border-color .15s, background .15s;
    height: 100%;
}
.dt-barang-card:hover {
    border-color: rgba(var(--primary-rgb, 99, 102, 241), .35);
}
.dt-barang-card.is-selected {
    border-color: var(--accent, #818cf8);
    background: rgba(var(--primary-rgb, 99, 102, 241), .08);
}
.dt-barang-card .form-check-input {
    width: 1.1rem;
    height: 1.1rem;
    margin-top: .15rem;
}
.dt-barang-card .dt-card-index {
    font-weight: 800;
    font-size: .9rem;
}
.dt-barang-grid { max-height: 420px; overflow-y: auto; }
@media (max-width: 991.98px) {
    .dt-summary-panel { position: static; margin-bottom: 1rem; }
}
</style>
@endpush

@push('scripts')
@php $dtI18n = \App\Support\JsTranslations::merge(['delivery_trip', 'common', 'container']); @endphp
<script>
    window.deliveryTripStoreUrl = @json(route('delivery-trip.store'));
    window.deliveryTripEligibleUrl = @json(route('delivery-trip.eligible-barangs'));
    window.deliveryTripConfig = {
        systemDateLabel: @json(now()->format('d/m/Y')),
    };
    Object.assign(window.__t || {}, @json($dtI18n));
</script>
<script src="{{ asset('Custom_js/DeliveryTrip/Create.js') }}?v={{ time() }}" defer></script>
@endpush
