@extends('layouts.app')
@section('title', __('container.packing_list_title', ['code' => $container->partai]))

@section('topbar-actions')
<a href="{{ route('container.index') }}" class="btn btn-glass btn-sm">
    <i class="bi bi-arrow-left me-1"></i>{{ __('container.back_to_list') }}
</a>
@endsection

@section('content')
{{-- Container Header --}}
<div class="glass p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h5 class="fw-800 mb-1" style="color:var(--text-primary)">
                <i class="bi bi-box-seam me-2 text-accent"></i>
                {{ __('container.packing_list_title', ['code' => $container->partai]) }}
            </h5>
            <div class="d-flex flex-wrap gap-3 mt-2" style="font-size:.82rem">
                @if($container->container_no)
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_container_meta') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->container_no }}</span>
                </div>
                @endif
                @if($container->no_bl)
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_no_bl_meta') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->no_bl }}</span>
                </div>
                @endif
                @if($container->port)
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_port_meta') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->port->name }}@if($container->port->city) ({{ $container->port->city }})@endif</span>
                </div>
                @endif
                @if($container->notes)
                <div class="w-100">
                    <span style="color:var(--text-muted)">{{ __('container.label_notes_meta') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->notes }}</span>
                </div>
                @endif
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_load_date_meta') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->load_date?->format('d M Y') ?? '-' }}</span>
                </div>
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_type') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->type }}</span>
                </div>
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_route_meta') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->route ?? '-' }}</span>
                </div>
                @if($container->etd)
                <div>
                    <span style="color:var(--text-muted)">ETD:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->etd->format('d M Y') }}</span>
                </div>
                @endif
                @if($container->eta)
                <div>
                    <span style="color:var(--text-muted)">ETA:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->eta->format('d M Y') }}</span>
                </div>
                @endif
                @if($container->is_join)
                <div><span class="badge-glass badge-status-inbound">{{ __('container.join_badge') }}</span></div>
                @endif
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @can('edit container')
            @if($container->canBeEdited() || auth()->user()?->hasAnyRole(['super_admin', 'supervisor']))
            <a href="{{ route('container.index', ['edit' => $container->id]) }}" class="btn btn-glass btn-sm">
                <i class="bi bi-pencil me-1"></i>{{ __('common.edit') }}
            </a>
            @endif
            @endcan
            @canany(['view invoice', 'export invoice'])
            <a href="{{ route('export.container-invoice.pdf', $container) }}" class="btn btn-accent btn-sm">
                <i class="bi bi-cash-stack me-1" style="color:#16a34a"></i>{{ __('container.download_invoice_pdf') }}
            </a>
            <a href="{{ route('export.container-invoice.excel', $container) }}" class="btn btn-glass btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i>{{ __('container.invoice_all_excel') }}
            </a>
            @endcanany
            @can('export packing list')
            <a href="{{ route('export.packing.excel', $container) }}"
               class="btn btn-glass btn-sm"
               data-bs-toggle="tooltip"
               data-bs-placement="top"
               title="{{ __('container.packing_list_excel_tooltip') }}">
                <i class="bi bi-file-earmark-excel me-1"></i>{{ __('container.packing_list_excel') }}
            </a>
            @endcan
            <a href="{{ route('container.index') }}" class="btn btn-glass btn-sm">
                <i class="bi bi-arrow-left me-1"></i>{{ __('common.back') }}
            </a>
        </div>
    </div>
</div>

@php
    $markings = $container->barangs->pluck('marking')->filter()->unique()->implode(', ');
    $preciseUsedVolume = (float) $barangs->sum(static fn ($barang): float => $barang->displayTotalCbm());
@endphp

{{-- Capacity Info Row --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="glass stat-card">
            <i class="bi bi-box-seam stat-icon"></i>
            <div class="stat-label">{{ __('container.stat_total_items') }}</div>
            <div class="stat-value" id="detailStatItems">{{ $container->barangs->count() }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="glass stat-card">
            <i class="bi bi-boxes stat-icon"></i>
            <div class="stat-label">{{ __('container.stat_total_coly') }}</div>
            <div class="stat-value" id="detailStatColy">{{ $container->total_ctns }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="glass stat-card">
            <i class="bi bi-rulers stat-icon"></i>
            <div class="stat-label">{{ __('container.stat_volume_used') }}</div>
            <div class="stat-value text-accent" id="detailStatVolume">{{ \App\Support\CbmFormat::format($preciseUsedVolume) }}</div>
            <div class="stat-change">/ {{ \App\Support\CbmFormat::format((float) $container->max_volume) }} CBM</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="glass stat-card">
            <i class="bi bi-speedometer2 stat-icon"></i>
            <div class="stat-label">{{ __('container.stat_capacity') }}</div>
            <div class="stat-value {{ $capacityLevel === 'danger' ? 'text-danger' : ($capacityLevel === 'warning' ? 'text-warning' : 'text-accent') }}" id="detailStatCapacity">
                {{ $capacityPercent }}%
            </div>
            <div class="capacity-bar mt-2">
                <div class="fill {{ $capacityLevel }}" id="detailCapacityBarFill" style="width: {{ min($capacityPercent, 100) }}%"></div>
            </div>
        </div>
    </div>
</div>

{{-- Markings summary --}}
@if($markings)
<div class="glass p-3 mb-4">
    <div class="small">
        <span style="color:var(--text-muted)"><i class="bi bi-bookmark-star me-1"></i>{{ __('container.label_markings') }}:</span>
        <span class="fw-600" style="color:var(--text-primary)">{{ $markings }}</span>
    </div>
</div>
@endif

{{-- Packing List Table --}}
<div class="glass p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)">
            <i class="bi bi-list-ul me-2 text-accent"></i>{{ __('container.item_list') }}
        </h6>
        <div class="d-flex flex-wrap align-items-center gap-2">
            @canany(['load barang', 'unload barang'])
            <a href="{{ route('container.load.page', $container) }}" class="btn btn-accent btn-sm">
                <i class="bi bi-box-arrow-in-down me-1"></i>{{ __('container.load_items') }}
            </a>
            <button type="button" class="btn btn-glass btn-sm btn-add-packaging">
                <i class="bi bi-upc-scan me-1"></i>{{ __('container.add_via_index') }}
            </button>
            @endcanany
            <span class="badge-glass" id="detailItemsBadge" style="color:var(--text-muted);font-size:.75rem">
                <i class="bi bi-hash me-1"></i>{{ __('container.items_count', ['count' => $container->barangs->count()]) }}
            </span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table-glass packing-list-table" id="packingTable">
            <thead>
                <tr>
                    <th>{{ __('container.col_no_index_po') }}</th>
                    <th class="col-mark">{{ __('container.col_marking') }}</th>
                    <th>{{ __('container.col_partai') }}</th>
                    <th>{{ __('container.col_coly_volume') }}</th>
                    <th>{{ __('container.col_resi') }}</th>
                    <th>{{ __('container.col_items') }}</th>
                    <th>{{ __('container.col_price') }}</th>
                    <th>{{ __('container.col_notes') }}</th>
                    <th>{{ __('container.col_ship_date') }}</th>
                    <th class="col-actions">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $canUnloadShipment = auth()->user()?->can('unload barang')
                        || auth()->user()?->hasAnyRole(['super_admin', 'supervisor', 'admin_gudang_china']);
                    $unloadLockedByDeparture = $container->hasDeparted()
                        && ! auth()->user()?->hasAnyRole(['super_admin', 'supervisor']);
                @endphp
                @include('container._partials.packing-table-tbody', [
                    'barangs' => $barangs,
                    'container' => $container,
                    'canUnloadShipment' => $canUnloadShipment,
                    'unloadLockedByDeparture' => $unloadLockedByDeparture,
                ])
            </tbody>
            @if($container->barangs->count() > 0)
            @include('container._partials.packing-table-foot', [
                'container' => $container,
                'preciseUsedVolume' => $preciseUsedVolume,
            ])
            @endif
        </table>
    </div>
</div>

{{-- Add Packaging Modal (load by No Urut) --}}
@can('load barang')
<div class="modal fade" id="addPackModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-plus-circle me-1 text-accent"></i>{{ __('container.modal_load_title', ['code' => $container->partai]) }}</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addPackForm">
                    <div class="mb-3">
                        <label class="form-label">{{ __('container.col_no_index') }}</label>
                        <input type="text" id="packNoIndex" class="form-control" placeholder="{{ __('container.placeholder_scan_index') }}" autofocus required>
                    </div>
                    <div id="packPreview" class="glass-solid p-3 mb-3 d-none"></div>

                    <div class="glass-solid p-3 mb-3">
                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.used_label') }}</div>
                                <div class="fw-700 small" style="color:var(--text-primary)" id="packModalUsedCbm">{{ \App\Support\CbmFormat::format($preciseUsedVolume) }} CBM</div>
                            </div>
                            <div class="col-4">
                                <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.remaining_label') }}</div>
                                <div class="text-accent fw-700 small" id="packModalRemainingCbm">{{ \App\Support\CbmFormat::format((float) $remainingVolume) }} CBM</div>
                            </div>
                            <div class="col-4">
                                <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.max_label') }}</div>
                                <div class="fw-700 small" style="color:var(--text-primary)" id="packModalMaxCbm">{{ \App\Support\CbmFormat::format((float) $container->max_volume) }} CBM</div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-accent w-100"><i class="bi bi-box-arrow-in-down me-1"></i>{{ __('container.load_to_container') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endcan

{{-- Re-Pricing / Ubah Harga Modal --}}
@canany(['submit repricing', 'change price'])
<div class="modal fade" id="repriceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700">
                    <i class="bi bi-tags-fill me-1 text-accent"></i>
                    {{ __('container.change_price_modal') }}
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="glass-solid p-3 mb-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.col_no_index') }}</div>
                            <div class="fw-700" style="color:var(--text-primary)" id="repriceIndex">-</div>
                        </div>
                        <div class="col-6">
                            <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.label_marking') }}</div>
                            <div class="fw-600" style="color:var(--text-primary)" id="repriceMarking">-</div>
                        </div>
                        <div class="col-6">
                            <div style="font-size:.65rem;color:var(--text-muted)">CBM</div>
                            <div class="fw-700 text-accent" id="repriceCbm">-</div>
                        </div>
                        <div class="col-6">
                            <div style="font-size:.65rem;color:var(--text-muted)">GW (kg)</div>
                            <div class="fw-600" style="color:var(--text-primary)" id="repriceGw">-</div>
                        </div>
                        <div class="col-6">
                            <div style="font-size:.65rem;color:var(--text-muted)">{{ __('finance.repricing_threshold') }}</div>
                            <div class="fw-600" style="color:var(--text-primary)" id="repriceThreshold">-</div>
                        </div>
                    </div>
                </div>

                <form id="repriceForm" enctype="multipart/form-data">
                    <input type="hidden" id="repriceBarangId">
                    <input type="hidden" name="pricing_mode" id="repricePricingMode" value="cbm">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="repriceWeightMode" value="1">
                        <label class="form-check-label" for="repriceWeightMode">{{ __('finance.repricing_weight_mode') }}</label>
                        <div class="form-text" style="color:var(--text-muted)">{{ __('finance.repricing_weight_mode_hint') }}</div>
                    </div>
                    <div id="repriceCbmFields">
                        <div class="mb-3">
                            <label class="form-label">{{ __('container.label_price_per_cbm') }}</label>
                            <input type="number" name="price_per_cbm" id="repricePrice" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div id="repriceWeightFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">{{ __('finance.repricing_price_per_kg') }}</label>
                            <input type="number" name="weight_price" id="repriceWeightPrice" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('container.label_extra_charge') }}</label>
                        <input type="number" name="extra_charge" id="repriceExtra" class="form-control" step="0.01" min="0" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('finance.repricing_reason') }} <span class="text-danger">*</span></label>
                        <textarea name="reason" id="repriceReason" class="form-control" rows="3" maxlength="1000" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('finance.repricing_proof') }} <span class="text-danger">*</span></label>
                        <input type="file" name="proof_image" id="repriceProof" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                    </div>

                    <div class="glass-solid p-3 mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span style="color:var(--text-muted)">{{ __('finance.repricing_calc_cbm') }}</span>
                            <span style="color:var(--text-primary)" id="rpCalcCbm2">Rp 0</span>
                        </div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span style="color:var(--text-muted)">{{ __('finance.repricing_calc_weight') }}</span>
                            <span style="color:var(--text-primary)" id="rpCalcWeight2">Rp 0</span>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span style="color:var(--text-muted)">{{ __('container.calc_extra') }}</span>
                            <span style="color:var(--text-primary)" id="rpCalcExtra2">Rp 0</span>
                        </div>
                        <hr style="border-color:var(--glass-border);margin:.5rem 0">
                        <div class="d-flex justify-content-between fw-700">
                            <span style="color:var(--text-primary)">{{ __('finance.repricing_estimasi_active') }}</span>
                            <span class="text-accent" id="rpCalcTotal2">Rp 0</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-accent w-100 py-2">
                        <i class="bi bi-check-lg me-1"></i>{{ __('container.save_price') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endcanany
@endsection

@push('styles')
<style>
.packing-coly-volume-stack {
    display: flex;
    flex-direction: column;
    gap: 3px;
    line-height: 1.3;
    font-size: .78rem;
    white-space: nowrap;
}
.packing-coly-volume-stack .packing-coly-volume-cbm {
    color: var(--accent);
    font-weight: 700;
}
.packing-row-actions {
    flex-wrap: wrap;
    gap: 0.35rem;
    justify-content: flex-end;
}
.btn-delete-barang {
    background: #dc2626 !important;
    border: 1px solid #b91c1c !important;
    color: #fff !important;
    font-weight: 600;
    white-space: nowrap;
}
.btn-delete-barang:hover:not(:disabled) {
    background: #b91c1c !important;
    border-color: #991b1b !important;
    color: #fff !important;
}
.btn-delete-barang:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
</style>
@endpush

@push('scripts')
@php
    $containerI18n = \App\Support\JsTranslations::merge(['container', 'finance', 'messages', 'common', 'swal']);
@endphp
<script>
    Object.assign(window.__t || {}, @json($containerI18n));
    window._detailConfig = {
        csrf: '{{ csrf_token() }}',
        containerId: {{ $container->id }},
        remainingVolume: {{ $remainingVolume }},
        unloadUrlTemplate: @json(route('container.unload', ['container' => $container->id, 'barang' => '__BARANG_ID__'])),
        packingTableUrl: @json(route('container.packing-table', $container)),
    };
</script>
<script src="{{ asset('Custom_js/Container/Detail.js') }}?v={{ time() }}" defer></script>
@endpush
