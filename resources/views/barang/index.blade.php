@extends('layouts.app')
@section('title', __('barang.title'))

@section('topbar-actions')
@can('create barang')
<a href="{{ route('barang.create') }}" class="btn btn-accent btn-sm">
    <i class="bi bi-plus-lg me-1"></i>{{ __('barang.new_barang') }}
</a>
@endcan
@endsection

@section('content')
@php
    $showEditPengajuan = ($canViewEditPengajuan ?? false);
    $tabListActive = ($activeTab ?? 'list') !== 'pengajuan-edit' || ! $showEditPengajuan;
    $pendingBadge = (int) ($pengajuanBadge ?? 0);
    $pendingBadgeLabel = $pendingBadge > 99 ? '99+' : (string) $pendingBadge;
@endphp

@if($showEditPengajuan)
@php
    $pendingBadgeTitle = __('barang.edit_pengajuan_pending_count', ['count' => $pendingBadge]);
@endphp
<div class="finance-ops-nav glass mb-4">
    <div class="finance-ops-segment" id="barangOpsTabs" role="tablist" aria-label="{{ __('barang.title') }}">
        <button type="button"
                class="finance-ops-tab {{ $tabListActive ? 'is-active' : '' }}"
                id="tab-barang-list-btn"
                role="tab"
                aria-selected="{{ $tabListActive ? 'true' : 'false' }}"
                aria-controls="tabBarangList"
                data-bs-target="#tabBarangList">
            <span class="finance-ops-tab-icon"><i class="bi bi-box-seam-fill" aria-hidden="true"></i></span>
            <span class="finance-ops-tab-text">{{ __('barang.list_title') }}</span>
        </button>
        <button type="button"
                class="finance-ops-tab {{ ! $tabListActive ? 'is-active' : '' }}"
                id="tab-pengajuan-edit-btn"
                role="tab"
                aria-selected="{{ ! $tabListActive ? 'true' : 'false' }}"
                aria-controls="tabPengajuanEdit"
                data-bs-target="#tabPengajuanEdit">
            <span class="finance-ops-tab-icon"><i class="bi bi-send-check-fill" aria-hidden="true"></i></span>
            <span class="finance-ops-tab-text">{{ __('barang.tab_edit_pengajuan') }}</span>
            <span id="tabBarangEditPengajuanBadge"
                  class="finance-ops-tab-badge {{ $pendingBadge > 0 ? '' : 'd-none' }}"
                  title="{{ $pendingBadgeTitle }}">{{ $pendingBadgeLabel }}</span>
        </button>
    </div>
</div>
@endif

<div class="tab-content" id="barangOpsTabContent">
<div class="tab-pane fade {{ $tabListActive ? 'show active' : '' }}" id="tabBarangList" role="tabpanel">
<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)">
            <i class="bi bi-box-seam me-2"></i>{{ __('barang.list_title') }}
        </h6>
        @can('export barang')
        <a href="{{ route('barang.list.export-excel') }}" class="btn btn-glass btn-sm" id="btnExportBarangList" target="_blank" rel="noopener"
           title="{{ __('barang.tooltip_export_list_excel') }}">
            <i class="bi bi-file-earmark-excel me-1" style="color:#16a34a"></i>{{ __('barang.export_list_excel') }}
        </a>
        @endcan
    </div>

    <div class="filter-bar barang-list-filter-bar p-3 mb-3" style="border-bottom:1px solid var(--glass-border)">
        <div class="row g-2 barang-filter-row">
            <div class="col-lg-3 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterDatePreset"><i class="bi bi-calendar-range me-1"></i>{{ __('barang.filter_tanggal') }}</label>
                <select id="filterDatePreset" class="form-select">
                    <option value="">{{ __('common.date_preset_none') }}</option>
                    <option value="today">{{ __('common.date_preset_today') }}</option>
                    <option value="yesterday">{{ __('common.date_preset_yesterday') }}</option>
                    <option value="7d">{{ __('common.date_preset_7d') }}</option>
                    <option value="30d">{{ __('common.date_preset_30d') }}</option>
                    <option value="custom">{{ __('common.date_preset_custom') }}</option>
                </select>
            </div>
            <div class="col-lg-3 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterNoIndex"><i class="bi bi-hash me-1"></i>{{ __('barang.col_no_index') }}</label>
                <input type="text" id="filterNoIndex" class="form-control" placeholder="{{ __('barang.placeholder_no_index') }}">
            </div>
            <div class="col-lg-3 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterMarking"><i class="bi bi-bookmark-star me-1"></i>{{ __('barang.col_marking') }}</label>
                <select id="filterMarking" name="marking[]" class="form-select select2" multiple data-placeholder="{{ __('barang.filter_all_marking') }}">
                    @foreach($markings as $mk)
                    <option value="{{ $mk->displayCode() }}">{{ $mk->dropdownLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-3 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterStatus"><i class="bi bi-flag me-1"></i>{{ __('barang.col_status') }}</label>
                <select id="filterStatus" class="form-select select2" data-placeholder="{{ __('common.all_status') }}">
                    <option value="">{{ __('common.all_status') }}</option>
                    @foreach($statusOptions as $st)
                    <option value="{{ $st }}">{{ __('barang.status_' . $st) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="row g-2 barang-filter-row mt-2">
            <div class="col-lg-2 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterPricingItem"><i class="bi bi-tags me-1"></i>{{ __('barang.filter_item_type') }}</label>
                <select id="filterPricingItem" name="pricing_item_id[]" class="form-select select2" multiple data-placeholder="{{ __('barang.filter_all_item_type') }}">
                    @foreach($pricingItems as $item)
                    <option value="{{ $item->id }}">{{ $item->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterCity"><i class="bi bi-geo-alt me-1"></i>{{ __('barang.filter_destination') }}</label>
                <select id="filterCity" class="form-select select2" data-placeholder="{{ __('barang.filter_all_destination') }}">
                    <option value="">{{ __('barang.filter_all_destination') }}</option>
                    @foreach($cities as $city)
                    <option value="{{ $city }}">{{ $city }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterGudang"><i class="bi bi-building me-1"></i>{{ __('barang.col_warehouse') }}</label>
                <select id="filterGudang" class="form-select select2" data-placeholder="{{ __('barang.filter_all_gudang') }}">
                    <option value="">{{ __('barang.filter_all_gudang') }}</option>
                    @foreach($gudangList as $gudang)
                    <option value="{{ $gudang->id }}">{{ $gudang->name }}{{ $gudang->address ? ' — ' . $gudang->address : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterPartai"><i class="bi bi-box-seam me-1"></i>{{ __('barang.filter_partai') }}</label>
                <select id="filterPartai" class="form-select select2" data-placeholder="{{ __('barang.filter_all_partai') }}">
                    <option value="">{{ __('barang.filter_all_partai') }}</option>
                    <option value="belum_di_muat">{{ __('barang.partai_not_loaded') }}</option>
                    @foreach($partaiOptions as $partai)
                    <option value="{{ $partai }}">{{ $partai }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-6 barang-filter-field">
                <label class="form-label mb-1" for="filterNoResi"><i class="bi bi-upc-scan me-1"></i>{{ __('barang.filter_no_resi') }}</label>
                <input type="text" id="filterNoResi" class="form-control" placeholder="{{ __('barang.filter_no_resi_placeholder') }}" autocomplete="off">
            </div>
            <div class="col-lg-2 col-md-6 filter-actions">
                <label class="form-label mb-1 d-none d-lg-block">&nbsp;</label>
                <button type="button" class="btn btn-glass btn-sm px-3" id="btnResetFilter">
                    <i class="bi bi-x-lg"></i><span>{{ __('common.reset') }}</span>
                </button>
            </div>
        </div>
        <div id="filterDateCustomRow" class="row g-2 barang-filter-row d-none">
            <div class="col-lg col-md-4 barang-filter-field">
                <label class="form-label mb-1" for="filterTanggalFrom"><i class="bi bi-calendar-event me-1"></i>{{ __('barang.filter_tanggal_from') }}</label>
                <input type="date" id="filterTanggalFrom" class="form-control" autocomplete="off">
            </div>
            <div class="col-lg col-md-4 barang-filter-field">
                <label class="form-label mb-1" for="filterTanggalTo"><i class="bi bi-calendar-check me-1"></i>{{ __('barang.filter_tanggal_to') }}</label>
                <input type="date" id="filterTanggalTo" class="form-control" autocomplete="off">
            </div>
        </div>
        <div id="activeFilters" class="d-flex flex-wrap gap-1 mt-2" style="min-height:0"></div>
    </div>

    <div class="barang-list-table-wrap table-responsive">
        <table id="barangTable" class="table table-hover w-100 barang-list-table">
            <thead>
                <tr>
                    <th class="barang-col-meta">{{ __('barang.col_tanggal_masuk') }}</th>
                    <th class="barang-col-marking">{{ __('barang.col_marking_customer') }}</th>
                    <th class="barang-col-barang">{{ __('barang.col_barang') }}</th>
                    <th class="barang-col-notes">{{ __('barang.col_notes') }}</th>
                    <th class="barang-col-packing">{{ __('barang.col_packing') }}</th>
                    <th class="barang-col-partai">{{ __('barang.col_partai') }}</th>
                    <th class="barang-col-resi">{{ __('barang.col_resi') }}</th>
                    <th class="barang-col-volume">{{ __('barang.col_volume_summary') }}</th>
                    <th class="barang-col-status">{{ __('barang.col_status') }}</th>
                    <th class="barang-col-actions text-center">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
</div>

@if($showEditPengajuan)
<div class="tab-pane fade {{ ! $tabListActive ? 'show active' : '' }}" id="tabPengajuanEdit" role="tabpanel">
    @include('barang._tab-pengajuan-edit')
</div>
@endif
</div>

@if($showEditPengajuan)
@include('barang._edit-request-modal')
@endif

{{-- MODAL: Import Excel --}}
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700"><i class="bi bi-upload me-1 text-accent"></i>{{ __('barang.import_modal_title') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                    <small class="text-muted mt-2 d-block">{{ __('barang.import_file_hint') }}</small>
                </div>
                @can('create barang')
                <div class="modal-footer">
                    <button type="submit" class="btn btn-accent"><i class="bi bi-upload me-1"></i>{{ __('common.import') }}</button>
                </div>
                @endcan
            </form>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.barang-list-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    max-width: 100%;
}
.barang-list-table {
    min-width: 1180px;
}
#barangTable th.barang-col-resi,
#barangTable td.barang-col-resi {
    min-width: 120px;
    max-width: 160px;
    vertical-align: top;
}
.barang-resi-stack {
    display: flex;
    flex-direction: column;
    gap: 3px;
    line-height: 1.3;
    font-size: .72rem;
}
.barang-resi-stack .barang-resi-line {
    word-break: break-all;
    color: var(--text-primary);
}
#barangTable th.barang-col-meta,
#barangTable td.barang-col-meta {
    min-width: 108px;
    white-space: nowrap;
    vertical-align: top;
}
.barang-meta-stack {
    display: flex;
    flex-direction: column;
    gap: 2px;
    line-height: 1.35;
}
.barang-meta-stack .barang-meta-date {
    font-size: .72rem;
    color: var(--text-muted);
    white-space: nowrap;
}
.barang-meta-stack .barang-meta-index {
    font-weight: 700;
    font-size: .78rem;
    color: var(--text-primary);
    line-height: 1.25;
}
.barang-meta-stack .barang-meta-gudang,
.barang-meta-stack .barang-meta-city {
    font-size: .68rem;
    color: var(--text-muted);
    line-height: 1.25;
}
.barang-meta-stack .barang-meta-city {
    color: var(--text-secondary);
}
#barangTable th.barang-col-volume,
#barangTable td.barang-col-volume {
    min-width: 100px;
    white-space: nowrap;
    vertical-align: top;
}
.barang-volume-stack {
    display: flex;
    flex-direction: column;
    gap: 3px;
    line-height: 1.3;
    font-size: .72rem;
}
.barang-volume-stack .barang-volume-cbm {
    color: var(--accent, #f59e0b);
    font-weight: 700;
}
#barangTable th.barang-col-actions,
#barangTable td.barang-col-actions {
    position: sticky;
    right: 0;
    z-index: 5;
    background-color: #eef2f7 !important;
    border-left: 1px solid var(--glass-border);
    box-shadow: -8px 0 16px rgba(10, 34, 64, .12);
    min-width: 132px;
    white-space: nowrap;
    vertical-align: middle;
}
#barangTable thead th.barang-col-actions {
    z-index: 6;
    background-color: #e2e8f0 !important;
}
[data-theme="dark"] #barangTable th.barang-col-actions,
[data-theme="dark"] #barangTable td.barang-col-actions {
    background-color: #132d4f !important;
    box-shadow: -8px 0 16px rgba(0, 0, 0, .35);
}
[data-theme="dark"] #barangTable thead th.barang-col-actions {
    background-color: #1a3a5c !important;
}
#barangTable tbody tr:hover td.barang-col-actions {
    background-color: #e2e8f0 !important;
}
[data-theme="dark"] #barangTable tbody tr:hover td.barang-col-actions {
    background-color: #1a3a5c !important;
}
#barangTable td.barang-col-actions .d-flex {
    background-color: inherit;
}
#barangTable th.barang-col-notes,
#barangTable td.barang-col-notes {
    min-width: 140px;
    max-width: 220px;
    vertical-align: top;
}
#barangTable th.barang-col-packing,
#barangTable td.barang-col-packing { min-width: 88px; }
#barangTable th.barang-col-partai,
#barangTable td.barang-col-partai { min-width: 108px; }
#barangTable th.barang-col-barang,
#barangTable td.barang-col-barang {
    min-width: 160px;
    max-width: 280px;
    vertical-align: top;
}
.barang-cell-wrap {
    display: block;
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
    line-height: 1.35;
}
</style>
@endpush

@push('scripts')
@php
    $barangI18n = \App\Support\JsTranslations::merge(['barang', 'common', 'tracking', 'swal']);
@endphp
<script src="{{ asset('Custom_js/shared/DatePresetFilter.js') }}?v={{ time() }}"></script>
<script>
    var token = "{{ csrf_token() }}";
    Object.assign(window.__t || {}, @json($barangI18n));
    var _canEditBarang = @json(auth()->user()->can('edit barang'));
    var _canDownloadInvoice = @json(auth()->user()->canAny(['view invoice', 'export invoice', 'download barang invoice']));
    var _canDeleteBarang = @json(auth()->user()->can('delete barang'));
    var _canExportProducts = @json(auth()->user()->can('export barang'));
    var _barangListExportUrl = @json(route('barang.list.export-excel'));
    var _barangEditUrlTemplate = @json(route('barang.edit', ['barang' => '__ID__']));
    var _barangStatusUpdateUrlTemplate = @json(route('tracking.update', ['barang' => '__ID__']));
    var _canWithdrawBarang = @json(\App\Models\Barang::userCanSetDitarikKembali(auth()->user()));
</script>
<script src="{{ asset('Custom_js/Barang/Index.js') }}?v={{ time() }}" defer></script>
@if($showEditPengajuan ?? false)
<script>
    window._barangEditPengajuanCanApprove = @json($canApproveEditPengajuan ?? false);
    window._barangEditPengajuanCanReject = @json($canRejectEditPengajuan ?? false);
    window._barangEditPengajuanUrls = {
        data: @json(route('barang.edit-requests.data')),
        badge: @json(route('barang.edit-requests.badge')),
        show: @json(url('/barang/edit-requests/__ID__')),
        approve: @json(url('/barang/edit-requests/__ID__/approve')),
        reject: @json(url('/barang/edit-requests/__ID__/reject')),
    };
</script>
<script src="{{ asset('Custom_js/shared/FinanceOpsTabs.js') }}?v={{ time() }}" defer></script>
<script src="{{ asset('Custom_js/Barang/PengajuanEdit.js') }}?v={{ time() }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.initFinanceOpsTabs !== 'function') {
        return;
    }
    var params = new URLSearchParams(window.location.search);
    var initialPane = params.get('tab') === 'pengajuan-edit' ? '#tabPengajuanEdit' : null;
    var tabConfig = {
        tabsRootId: 'barangOpsTabs',
        contentId: 'barangOpsTabContent',
        defaultUrl: @json(route('barang.index')),
        urlByPane: {
            '#tabPengajuanEdit': @json(route('barang.index', ['tab' => 'pengajuan-edit'])),
        },
        initialPane: initialPane,
        onShow: {
            '#tabPengajuanEdit': function () {
                if (typeof window.barangPengajuanEditOnTabShow === 'function') {
                    window.barangPengajuanEditOnTabShow();
                }
            },
            '#tabBarangList': function () {
                if (typeof window.barangListOnTabShow === 'function') {
                    window.barangListOnTabShow();
                }
            },
        },
    };

    // Defer until deferred tab scripts (PengajuanEdit.js) register onShow handlers.
    window.setTimeout(function () {
        window.initFinanceOpsTabs(tabConfig);
    }, 0);
});
</script>
@endif
@endpush


