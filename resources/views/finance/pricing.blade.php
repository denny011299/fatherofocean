@extends('layouts.app')
@section('title', __('finance.pricing_title'))

@section('topbar-actions')
@can('view invoice')
<a href="{{ route('finance.index') }}" class="btn btn-glass btn-sm"><i class="bi bi-receipt me-1"></i>{{ __('nav.invoice_payment') }}</a>
@endcan
@endsection

@section('content')
@php
    $showPengiriman = auth()->user()?->can('view pricing') ?? false;
    $showPengajuan = ($canViewPengajuan ?? false);
    $tabPengirimanActive = ($activeTab ?? 'pengiriman') !== 'pengajuan' || ! $showPengajuan;
    if (! $showPengiriman && $showPengajuan) {
        $tabPengirimanActive = false;
    }
@endphp

@php
    $pendingBadge = (int) ($pengajuanBadge ?? 0);
    $pendingBadgeLabel = $pendingBadge > 99 ? '99+' : (string) $pendingBadge;
@endphp
<div class="finance-ops-nav glass mb-4">
    <div class="finance-ops-segment" id="financeOpsTabs" role="tablist" aria-label="{{ __('finance.pricing_title') }}">
        @if($showPengiriman)
        <button type="button"
                class="finance-ops-tab {{ $tabPengirimanActive ? 'is-active' : '' }}"
                id="tab-pengiriman-btn"
                role="tab"
                aria-selected="{{ $tabPengirimanActive ? 'true' : 'false' }}"
                aria-controls="tabPengiriman"
                data-bs-target="#tabPengiriman">
            <span class="finance-ops-tab-icon"><i class="bi bi-truck-front-fill" aria-hidden="true"></i></span>
            <span class="finance-ops-tab-text">{{ __('finance.tab_pengiriman') }}</span>
        </button>
        @endif
        @if($showPengajuan)
        <button type="button"
                class="finance-ops-tab {{ ! $tabPengirimanActive ? 'is-active' : '' }}"
                id="tab-pengajuan-btn"
                role="tab"
                aria-selected="{{ ! $tabPengirimanActive ? 'true' : 'false' }}"
                aria-controls="tabPengajuan"
                data-bs-target="#tabPengajuan">
            <span class="finance-ops-tab-icon"><i class="bi bi-send-check-fill" aria-hidden="true"></i></span>
            <span class="finance-ops-tab-text">{{ __('finance.tab_pengajuan') }}</span>
            <span id="tabPengajuanBadge"
                  class="finance-ops-tab-badge {{ $pendingBadge > 0 ? '' : 'd-none' }}"
                  title="{{ __('finance.pengajuan_pending_count', ['count' => $pendingBadge]) }}">{{ $pendingBadgeLabel }}</span>
        </button>
        @endif
    </div>
</div>

<div class="tab-content" id="financeOpsTabContent">
    @if($showPengiriman)
    <div class="tab-pane fade {{ $tabPengirimanActive ? 'show active' : '' }}" id="tabPengiriman" role="tabpanel">
        @include('finance._tab-pengiriman')
    </div>
    @endif
    @if($showPengajuan)
    <div class="tab-pane fade {{ ! $tabPengirimanActive ? 'show active' : '' }}" id="tabPengajuan" role="tabpanel">
        @include('finance._tab-pengajuan')
    </div>
    @endif
</div>

@include('finance._reprice-modal')
@endsection

@push('scripts')
@php
    $financeI18n = \App\Support\JsTranslations::merge(['finance', 'common', 'swal', 'barang']);
@endphp
<script>Object.assign(window.__t || {}, @json($financeI18n));</script>
<script>
    window._financeIndexUrl = @json(route('finance.index'));
    window._canViewFinanceInvoice = @json(auth()->user()?->can('view invoice') ?? false);
    window._canSubmitRepricing = @json(auth()->user()?->can('submit repricing') || auth()->user()?->can('change price'));
    window._canApproveRepricing = @json(auth()->user()?->can('approve repricing'));
    window._canEditCostPrice = @json(auth()->user()?->can('edit harga modal') ?? false);
    window._pengajuanCanApprove = @json($canApprove ?? false);
    window._pengajuanCanSubmit = @json($canSubmit ?? false);
</script>
@if($showPengiriman)
<script src="{{ asset('Custom_js/Finance/Pricing.js') }}?v={{ time() }}" defer></script>
@endif
@if($showPengajuan)
<script src="{{ asset('Custom_js/Finance/Pengajuan.js') }}?v={{ time() }}" defer></script>
@endif
@if($showPengiriman && $showPengajuan)
<script src="{{ asset('Custom_js/shared/FinanceOpsTabs.js') }}?v={{ time() }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.initFinanceOpsTabs !== 'function') {
        return;
    }
    var params = new URLSearchParams(window.location.search);
    var initialPane = params.get('tab') === 'pengajuan' ? '#tabPengajuan' : null;
    var tabConfig = {
        tabsRootId: 'financeOpsTabs',
        contentId: 'financeOpsTabContent',
        defaultUrl: @json(route('finance.pricing')),
        urlByPane: {
            '#tabPengajuan': @json(route('finance.pricing', ['tab' => 'pengajuan'])),
        },
        initialPane: initialPane,
        onShow: {
            '#tabPengiriman': function () {
                if (typeof window.financePricingOnTabShow === 'function') {
                    window.financePricingOnTabShow();
                }
            },
            '#tabPengajuan': function () {
                if (typeof window.financePengajuanOnTabShow === 'function') {
                    window.financePengajuanOnTabShow();
                }
            },
        },
    };

    window.setTimeout(function () {
        window.initFinanceOpsTabs(tabConfig);
    }, 0);
});
</script>
@endif
@endpush
