@extends('layouts.app')
@section('title', __('delivery_trip.title') . ' — ' . ($container->partai ?? ''))

@section('topbar-actions')
@if(auth()->user()?->hasRole('super_admin'))
<a href="{{ route('tracking.staff.surat-jalan.template', $container) }}" target="_blank" rel="noopener"
   class="btn btn-glass btn-sm" title="{{ __('delivery_trip.blank_template_tooltip') }}">
    <i class="bi bi-file-earmark me-1"></i>{{ __('delivery_trip.blank_template_btn') }}
</a>
@endif
<a href="{{ route('tracking.staff') }}" class="btn btn-glass btn-sm">
    <i class="bi bi-arrow-left me-1"></i>{{ __('tracking.staff_title') }}
</a>
@endsection

@section('content')
<div class="glass p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h5 class="fw-800 mb-1" style="color:var(--text-primary)">
                <i class="bi bi-file-earmark-text me-2 text-accent"></i>{{ __('delivery_trip.title') }}
            </h5>
            <div class="small" style="color:var(--text-muted)">
                {{ $container->partai }}
                @if($container->container_no)
                · {{ $container->container_no }}
                @endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge-glass" id="sjPageTripNo">{{ $payload['trip']['trip_no'] ?? 'Draft' }}</span>
            <span class="badge-glass {{ $payload['trip']['status_badge'] ?? 'badge-status-pending' }}" id="sjPageStatus">
                {{ $payload['trip']['status_label'] ?? __('delivery_trip.status_ready') }}
            </span>
        </div>
    </div>
</div>

<div class="glass p-4" id="suratJalanPageRoot">
    @include('tracking._partials.surat-jalan-workspace')
</div>
@endsection

@push('styles')
<style>
.sj-bukti-popup .swal2-html-container { max-height: 70vh; overflow-y: auto; text-align: left; }
.sj-bukti-phase { border: 1px solid var(--glass-border); border-radius: 12px; padding: 14px; background: rgba(255,255,255,.02); }
.sj-bukti-departure { border-left: 3px solid #f59e0b; }
.sj-bukti-arrival { border-left: 3px solid #22c55e; }
.sj-bukti-preview-thumb img { width: 72px; height: 72px; object-fit: cover; border-radius: 8px; border: 1px solid var(--glass-border); display: block; }
.sj-bukti-preview-video { padding: 6px 8px; border-radius: 8px; background: rgba(255,255,255,.04); border: 1px solid var(--glass-border); }
</style>
@endpush

@push('scripts')
<script>
    window.SURAT_JALAN_PAGE_BOOT = {
        payload: @json($payload),
        csrf: @json(csrf_token()),
        containerId: {{ $container->id }},
        upsertUrl: @json(route('tracking.staff.delivery-trip.upsert', $container)),
        summaryUrl: @json(route('tracking.staff.delivery-trip.summary', $container)),
        canBlankTemplate: @json(auth()->user()?->hasRole('super_admin')),
        blankTemplateUrl: @json(auth()->user()?->hasRole('super_admin') ? route('tracking.staff.surat-jalan.template', $container) : null),
        blankTemplateLabel: @json(__('delivery_trip.blank_template_btn')),
        isStandalonePage: true,
        onRendered: null,
    };
</script>
@include('tracking._partials.surat-jalan-scripts')
@endpush
