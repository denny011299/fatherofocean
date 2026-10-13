@extends('layouts.app')
@section('title', __('container.load_estimation_title', ['code' => $container->partai]))

@section('topbar-actions')
<a href="{{ route('container.index') }}" class="btn btn-glass btn-sm">
    <i class="bi bi-arrow-left me-1"></i>{{ __('container.back_to_list') }}
</a>
@endsection

@section('content')
<div class="form-page-with-sticky-footer">
<div class="glass p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h5 class="fw-800 mb-1" style="color:var(--text-primary)">
                <i class="bi bi-box-arrow-in-down me-2 text-accent"></i>
                {{ __('container.load_estimation_title', ['code' => $container->partai]) }}
            </h5>
            <div class="d-flex flex-wrap gap-3 mt-2" style="font-size:.82rem">
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_china_wh') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->gudangChina?->name ?? $container->alamat_gudang_china ?? '-' }}</span>
                </div>
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_route_meta') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->route ?? '-' }}</span>
                </div>
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_type') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->type }}</span>
                </div>
                @if($container->container_no)
                <div>
                    <span style="color:var(--text-muted)">{{ __('container.label_container_meta') }}:</span>
                    <span class="fw-600" style="color:var(--text-primary)">{{ $container->container_no }}</span>
                </div>
                @endif
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('container.detail', $container) }}" class="btn btn-glass btn-sm">
                <i class="bi bi-box-seam me-1"></i>{{ __('container.packing_list') }}
            </a>
            <a href="{{ route('container.index') }}" class="btn btn-glass btn-sm">
                <i class="bi bi-arrow-left me-1"></i>{{ __('common.back') }}
            </a>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="glass stat-card">
            <i class="bi bi-box-seam stat-icon"></i>
            <div class="stat-label">{{ __('container.items_loaded') }}</div>
            <div class="stat-value" id="statLoadedItems">{{ $container->barangs->count() }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="glass stat-card">
            <i class="bi bi-boxes stat-icon"></i>
            <div class="stat-label">{{ __('container.stat_total_coly') }}</div>
            <div class="stat-value" id="statLoadedCtns">{{ $container->total_ctns }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="glass stat-card">
            <i class="bi bi-rulers stat-icon"></i>
            <div class="stat-label">{{ __('container.stat_volume_used') }}</div>
            <div class="stat-value text-accent" id="statUsedVolume">{{ number_format($usedVolume, 2) }}</div>
            <div class="stat-change">/ <span id="statMaxVolume">{{ number_format($container->max_volume, 2) }}</span> CBM</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="glass stat-card">
            <i class="bi bi-speedometer2 stat-icon"></i>
            <div class="stat-label">{{ __('container.stat_capacity') }}</div>
            <div class="stat-value {{ $capacityLevel === 'danger' ? 'text-danger' : ($capacityLevel === 'warning' ? 'text-warning' : 'text-accent') }}" id="statCapacityPercent">
                {{ $capacityPercent }}%
            </div>
            <div class="capacity-bar mt-2">
                <div class="fill {{ $capacityLevel }}" id="statCapacityBar" style="width: {{ min($capacityPercent, 100) }}%"></div>
            </div>
            <div class="stat-change mt-1">{{ __('container.remaining_label') }} <span id="statRemainingVolume">{{ \App\Support\CbmFormat::format((float) $remainingVolume) }}</span> CBM</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="glass p-4 h-100">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
                <i class="bi bi-bookmark-star me-2 text-accent"></i>{{ __('container.add_via_marking') }}
            </h6>
            @include('container._marking-estimation-filters')
            <div class="mb-3">
                <label class="form-label">{{ __('container.select_marking_label') }} <span class="text-muted fw-normal small">({{ __('common.optional') }})</span></label>
                <select id="markingSelect" class="form-select select2" data-placeholder="{{ __('container.placeholder_search_marking_optional') }}" data-allow-clear="1">
                    <option value=""></option>
                    @foreach($markings as $marking)
                    <option value="{{ $marking->id }}">{{ $marking->dropdownLabel() }}</option>
                    @endforeach
                </select>
            </div>

            <div id="markingPreviewEmpty" class="text-center py-4">
                <i class="bi bi-bookmark" style="font-size:2rem;opacity:.35;color:var(--text-dim)"></i>
                <div class="mt-2 small" style="color:var(--text-muted)">{{ __('container.preview_empty_load') }}</div>
            </div>

            <div id="markingPreviewPanel" class="d-none">
                <div class="glass-solid p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-700" style="color:var(--text-primary)" id="previewMarkingCode">-</div>
                            <div class="small" style="color:var(--text-muted)" id="previewCustomer">-</div>
                        </div>
                        <span class="badge-glass" id="previewFitBadge">-</span>
                    </div>
                    <div class="row g-2 mt-2 text-center">
                        <div class="col-4">
                            <div style="font-size:.65rem;color:var(--text-muted)">COLY</div>
                            <div class="fw-700 small" style="color:var(--text-primary)" id="previewCtns">0</div>
                        </div>
                        <div class="col-4">
                            <div style="font-size:.65rem;color:var(--text-muted)">CBM</div>
                            <div class="fw-700 small text-accent" id="previewCbm">0</div>
                        </div>
                        <div class="col-4">
                            <div style="font-size:.65rem;color:var(--text-muted)">GW (kg)</div>
                            <div class="fw-700 small" style="color:var(--text-primary)" id="previewGw">0</div>
                        </div>
                    </div>
                </div>

                <div id="previewBarangList" class="mb-3"></div>

                <button type="button" class="btn btn-accent w-100" id="btnAddToStage" disabled>
                    <i class="bi bi-plus-circle me-1"></i>{{ __('container.add_to_list') }}
                </button>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="glass p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-700 mb-0" style="color:var(--text-primary)">
                    <i class="bi bi-list-check me-2 text-accent"></i>{{ __('container.staging_list') }}
                </h6>
                <span class="badge-glass" style="color:var(--text-muted);font-size:.75rem">
                    <i class="bi bi-hash me-1"></i><span id="stageCount">0</span> <span class="stage-count-suffix">{{ __('container.items_count_suffix') }}</span>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table-glass w-100" id="stageTable">
                    <thead>
                        <tr>
                            <th>{{ __('container.col_marking') }}</th>
                            <th>{{ __('container.col_no_index') }}</th>
                            <th>{{ __('container.col_coly') }}</th>
                            <th>CBM</th>
                            <th>{{ __('container.col_gw') }}</th>
                            <th class="text-center">{{ __('container.col_action') }}</th>
                        </tr>
                    </thead>
                    <tbody id="stageTableBody">
                        <tr id="stageEmptyRow">
                            <td colspan="6" class="text-center py-4">
                                <i class="bi bi-inbox" style="font-size:2rem;opacity:.4;color:var(--text-dim)"></i>
                                <div class="mt-2" style="color:var(--text-muted)">{{ __('container.staging_empty') }}</div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot id="stageTableFoot" class="d-none">
                        <tr style="border-top:2px solid var(--glass-border)">
                            <td class="fw-700" colspan="2" style="color:var(--text-primary)">{{ __('container.staging_total') }}</td>
                            <td class="fw-700" id="stageTotalCtns" style="color:var(--text-primary)">0</td>
                            <td class="fw-800 text-accent" id="stageTotalCbm">0</td>
                            <td class="fw-700" id="stageTotalGw" style="color:var(--text-primary)">0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="glass p-4">
            <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
                <i class="bi bi-calculator me-2 text-accent"></i>{{ __('container.capacity_estimation') }}
            </h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="glass-solid p-3 text-center h-100">
                        <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.used_plus_staging') }}</div>
                        <div class="fw-800 text-accent" id="estimateUsedCbm">0</div>
                        <div class="small" style="color:var(--text-muted)">CBM</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="glass-solid p-3 text-center h-100">
                        <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.remaining_after_staging') }}</div>
                        <div class="fw-800" id="estimateRemainingCbm" style="color:var(--text-primary)">0</div>
                        <div class="small" style="color:var(--text-muted)">CBM</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="glass-solid p-3 text-center h-100">
                        <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.label_status') }}</div>
                        <div class="fw-800" id="estimateStatus">{{ __('container.fit_load') }}</div>
                        <div class="small" style="color:var(--text-muted)" id="estimatePercent" data-capacity-template="{{ __('container.capacity_percent', ['percent' => ':percent']) }}">0%</div>
                    </div>
                </div>
            </div>
            <div class="capacity-bar mb-3">
                <div class="fill safe" id="estimateCapacityBar" style="width:0%"></div>
            </div>
            <button type="button" class="btn btn-glass" id="btnClearStage" disabled>
                <i class="bi bi-x-lg me-1"></i>{{ __('container.clear_staging_list') }}
            </button>
        </div>
    </div>
</div>

<div class="form-sticky-footer">
    <div class="form-sticky-footer-inner">
        <a href="{{ route('container.index') }}" class="btn btn-glass">
            <i class="bi bi-x-lg me-1"></i>{{ __('common.cancel') }}
        </a>
        @can('load barang')
        <button type="button" class="btn btn-accent" id="btnSaveLoad" disabled>
            <i class="bi bi-check-lg me-1"></i>{{ __('container.save_load') }}
        </button>
        @endcan
    </div>
</div>
</div>
@endsection

@push('scripts')
@php
    $containerI18n = \App\Support\JsTranslations::merge(['container', 'messages', 'common', 'swal']);
@endphp
<script>
    Object.assign(window.__t || {}, @json($containerI18n));
    var _loadEstimationConfig = {
        csrf: '{{ csrf_token() }}',
        containerId: {{ $container->id }},
        usedVolume: {{ $usedVolume }},
        remainingVolume: {{ $remainingVolume }},
        maxVolume: {{ (float) $container->max_volume }},
        initialMarkingId: {{ $container->marking_id ? (int) $container->marking_id : 'null' }},
        markingPreviewUrl: '{{ route('container.marking-preview', $container) }}',
        loadBatchUrl: '{{ route('container.load-batch', $container) }}',
        detailUrl: '{{ route('container.detail', $container) }}',
        indexUrl: '{{ route('container.index') }}',
    };
</script>
<script src="{{ asset('Custom_js/shared/DatePresetFilter.js') }}?v={{ time() }}"></script>
<script src="{{ asset('Custom_js/Container/MarkingEstimationShared.js') }}?v={{ time() }}"></script>
<script src="{{ asset('Custom_js/Container/LoadEstimation.js') }}?v={{ time() }}" defer></script>
@endpush
