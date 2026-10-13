@extends('layouts.app')
@section('title', __('bongkar_muat.create_title'))

@section('topbar-actions')
<a href="{{ route('bongkar-muat.index') }}" class="btn btn-glass btn-sm">
    <i class="bi bi-arrow-left me-1"></i>{{ __('bongkar_muat.back_to_list') }}
</a>
@endsection

@section('content')
<div class="glass p-4 mb-3">
    <h5 class="fw-800 mb-1" style="color:var(--text-primary)">
        <i class="bi bi-box-arrow-in-down me-2 text-accent"></i>{{ __('bongkar_muat.create_title') }}
    </h5>
    <p class="small text-muted-glass mb-0">{{ __('bongkar_muat.create_subtitle') }}</p>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="glass p-4 h-100">
            <label class="form-label fw-600" for="containerAutocomplete">{{ __('bongkar_muat.select_container') }}</label>
            <input type="hidden" id="containerSelect">
            <div class="bm-autocomplete-wrap">
                <input
                    type="text"
                    id="containerAutocomplete"
                    class="form-control"
                    placeholder="{{ __('bongkar_muat.select_container_placeholder') }}"
                    autocomplete="off"
                >
                <div id="containerAutocompleteList" class="bm-autocomplete-list d-none"></div>
            </div>

            <div id="sessionSummary" class="mt-4 d-none">
                <div class="p-3 rounded-3" style="background:rgba(var(--accent-rgb),.08);border:1px solid var(--glass-border)">
                    <div class="small text-muted-glass">{{ __('bongkar_muat.progress') }}</div>
                    <div class="fw-800 fs-5" id="progressText">0 / 0</div>
                    <div class="progress mt-2" style="height:8px">
                        <div class="progress-bar bg-accent" id="progressBar" style="width:0%"></div>
                    </div>
                    <div class="small mt-2" id="sessionPartaiLabel"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="glass p-4 mb-3" id="scanPanel">
            <div class="text-center text-muted-glass py-4" id="scanPlaceholder">
                <i class="bi bi-upc-scan" style="font-size:2rem;opacity:.35"></i>
                <p class="mb-0 mt-2">{{ __('bongkar_muat.no_session') }}</p>
            </div>

            <div id="scanWorkspace" class="d-none">
                <label class="form-label fw-600" for="scanInput">{{ __('bongkar_muat.scan_index') }}</label>
                <div class="input-group mb-3">
                    <input type="text" id="scanInput" class="form-control form-control-lg font-monospace"
                           placeholder="{{ __('bongkar_muat.scan_placeholder') }}" autocomplete="off" inputmode="text">
                    <button type="button" class="btn btn-accent" id="btnLookup">
                        <i class="bi bi-search me-1"></i>{{ __('bongkar_muat.scan_btn') }}
                    </button>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <h6 class="fw-700 mb-2">
                            <i class="bi bi-check2-circle me-1 text-success"></i>{{ __('bongkar_muat.scanned_list') }}
                            <span class="badge bg-success ms-1" id="scannedCount">0</span>
                        </h6>
                        <div id="scannedList" class="bm-list"></div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-700 mb-2">
                            <i class="bi bi-hourglass-split me-1 text-warning"></i>{{ __('bongkar_muat.pending_list') }}
                            <span class="badge bg-warning text-dark ms-1" id="pendingCount">0</span>
                        </h6>
                        <div id="pendingList" class="bm-list"></div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top" style="border-color:var(--glass-border)!important">
                    <label class="form-label" for="sessionNotes">{{ __('bongkar_muat.session_notes') }}</label>
                    <input type="text" id="sessionNotes" class="form-control mb-3" maxlength="500">
                    <button type="button" class="btn btn-accent" id="btnComplete">
                        <i class="bi bi-check-lg me-1"></i>{{ __('bongkar_muat.complete_session') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="colyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass">
            <div class="modal-header border-0">
                <h6 class="modal-title fw-700">{{ __('bongkar_muat.confirm_coly') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <span class="badge bg-dark font-monospace" id="modalNoIndex">—</span>
                </div>
                <div class="small text-muted-glass mb-3" id="modalMeta"></div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">{{ __('bongkar_muat.expected_coly') }}</label>
                        <input type="text" class="form-control" id="modalExpected" readonly>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ __('bongkar_muat.actual_coly') }} <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="modalActual" min="0" step="1" required>
                    </div>
                </div>
                <div class="mt-3 d-none" id="varianceWrap">
                    <label class="form-label">{{ __('bongkar_muat.variance_note') }} <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="modalVarianceNote"
                           placeholder="{{ __('bongkar_muat.variance_note_placeholder') }}" maxlength="500">
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                <button type="button" class="btn btn-accent" id="btnModalConfirm">
                    <i class="bi bi-check-lg me-1"></i>{{ __('bongkar_muat.confirm_btn') }}
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.bm-list { max-height: 320px; overflow-y: auto; }
.bm-autocomplete-wrap { position: relative; }
.bm-autocomplete-list {
    position: absolute;
    top: calc(100% + .35rem);
    left: 0;
    right: 0;
    z-index: 30;
    border: 1px solid var(--glass-border);
    border-radius: .9rem;
    background: rgba(255,255,255,.98);
    box-shadow: 0 14px 30px rgba(15, 23, 42, .14);
    max-height: 260px;
    overflow-y: auto;
    padding: .35rem;
}
.bm-autocomplete-item {
    width: 100%;
    border: 0;
    background: transparent;
    text-align: left;
    border-radius: .7rem;
    padding: .55rem .7rem;
    color: var(--bs-body-color, #0f172a);
}
.bm-autocomplete-item:hover,
.bm-autocomplete-item:focus {
    background: rgba(var(--accent-rgb), .08);
    outline: none;
}
.bm-autocomplete-item-title {
    font-weight: 700;
    font-size: .88rem;
}
.bm-autocomplete-item-meta {
    font-size: .76rem;
    color: rgba(15, 23, 42, .65);
    margin-top: .1rem;
}
.bm-autocomplete-empty {
    padding: .6rem .75rem;
    font-size: .82rem;
    color: rgba(15, 23, 42, .65);
}
.bm-item {
    border: 1px solid var(--glass-border);
    border-radius: .65rem;
    padding: .55rem .65rem;
    margin-bottom: .5rem;
    background: rgba(255,255,255,.03);
}
.bm-item.variance { border-color: rgba(251, 113, 133, .45); background: rgba(251, 113, 133, .06); }
.bm-item .bm-index { font-family: ui-monospace, monospace; font-weight: 700; }
</style>
@endpush

@push('scripts')
@php
    $bmI18n = \App\Support\JsTranslations::merge(['bongkar_muat', 'container', 'common', 'messages', 'swal']);
    $resumeContainerId = $resumeSession !== null ? ($resumeSession['session']['container_id'] ?? null) : null;
@endphp
<script>
    Object.assign(window.__t || {}, @json($bmI18n));
    window.__bongkarMuatConfig = {
        containersUrl: @json(route('bongkar-muat.containers')),
        sessionOpenUrl: @json(route('bongkar-muat.session.open')),
        lookupTpl: @json(url('/bongkar-muat/session/__SID__/lookup')),
        confirmTpl: @json(url('/bongkar-muat/session/__SID__/confirm')),
        removeTpl: @json(url('/bongkar-muat/session/__SID__/scan/__SCAN__')),
        completeTpl: @json(url('/bongkar-muat/session/__SID__/complete')),
        indexUrl: @json(route('bongkar-muat.index')),
        initialContainers: @json($initialContainers),
        resumeSession: @json($resumeSession),
        resumeContainerId: @json($resumeContainerId),
    };
</script>
<script src="{{ asset('Custom_js/BongkarMuat/Create.js') }}?v={{ time() }}" defer></script>
@endpush
