@extends('layouts.app')
@section('title', 'Export Omset')

@section('content')
<div class="glass p-4 mb-4">
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-funnel" style="color: var(--accent)"></i>
        <h6 class="mb-0 fw-700" style="font-size:.9rem;color:var(--text-primary)">Filter Omset</h6>
    </div>
    <form id="filterForm" action="{{ route('export.omset') }}" method="get">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1" for="filterDatePreset">
                    <i class="bi bi-calendar-range me-1"></i>{{ __('barang.filter_tanggal') }}
                </label>
                <select id="filterDatePreset" name="date_preset" class="form-select">
                    <option value="" @selected(($filters->datePreset ?? '') === '')>{{ __('common.date_preset_none') }}</option>
                    <option value="today" @selected(($filters->datePreset ?? '') === 'today')>{{ __('common.date_preset_today') }}</option>
                    <option value="yesterday" @selected(($filters->datePreset ?? '') === 'yesterday')>{{ __('common.date_preset_yesterday') }}</option>
                    <option value="7d" @selected(($filters->datePreset ?? '') === '7d')>{{ __('common.date_preset_7d') }}</option>
                    <option value="30d" @selected(($filters->datePreset ?? '') === '30d')>{{ __('common.date_preset_30d') }}</option>
                    <option value="this_month" @selected(($filters->datePreset ?? '') === 'this_month')>{{ __('common.date_preset_this_month') }}</option>
                    <option value="this_year" @selected(($filters->datePreset ?? '') === 'this_year')>{{ __('common.date_preset_this_year') }}</option>
                    <option value="custom" @selected(($filters->datePreset ?? '') === 'custom')>{{ __('common.date_preset_custom') }}</option>
                </select>
            </div>
            <div class="col-md-4 {{ ($filters->datePreset ?? '') === 'custom' ? '' : 'd-none' }}" id="filterDateCustomRow">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label mb-1 small" for="filterTanggalFrom">{{ __('barang.filter_tanggal_from') }}</label>
                        <input type="date" id="filterTanggalFrom" name="tanggal_from" class="form-control"
                            value="{{ $filters->tanggalFrom ?? '' }}" autocomplete="off">
                    </div>
                    <div class="col-6">
                        <label class="form-label mb-1 small" for="filterTanggalTo">{{ __('barang.filter_tanggal_to') }}</label>
                        <input type="date" id="filterTanggalTo" name="tanggal_to" class="form-control"
                            value="{{ $filters->tanggalTo ?? '' }}" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" for="filterPartai">
                    <i class="bi bi-box-seam me-1"></i>{{ __('barang.filter_partai') }}
                </label>
                <select id="filterPartai" name="partai" class="form-select select2" data-placeholder="{{ __('barang.filter_all_partai') }}">
                    <option value="">{{ __('barang.filter_all_partai') }}</option>
                    <option value="belum_di_muat" @selected(($filters->partai ?? '') === 'belum_di_muat')>{{ __('barang.partai_not_loaded') }}</option>
                    @foreach($partaiOptions as $partai)
                        <option value="{{ $partai }}" @selected(($filters->partai ?? '') === $partai)>{{ $partai }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 col-lg-1">
                <button type="button" class="btn btn-glass w-100" id="btnResetOmsetFilter" title="{{ __('common.reset') }}">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="col-md-2 col-lg-2">
                <button type="submit" class="btn btn-accent w-100" id="btnShowOmset">
                    <i class="bi bi-search me-1"></i> Tampilkan
                </button>
            </div>
        </div>
    </form>
</div>

<div id="omsetResults">
    <div class="glass p-5 text-center" id="omsetInitialState">
        <i class="bi bi-graph-up-arrow" style="font-size:2rem;opacity:.4;color:var(--accent)"></i>
        <p class="mt-3 mb-1 fw-600" style="color:var(--text-primary)">Export Laporan Omset</p>
        <p class="mb-0" style="font-size:.85rem;color:var(--text-muted)">Pilih filter tanggal masuk dan/atau partai, lalu klik "Tampilkan".</p>
        <p class="mb-0 mt-2" style="font-size:.8rem;color:var(--text-muted)">Periode dihitung dari <strong style="color:var(--text-primary)">Tanggal Masuk</strong> barang (kolom yang sama di export Excel).</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
    window.omsetDataUrl = @json(route('export.omset.data'));
    window.omsetPageUrl = @json(route('export.omset'));
    window.omsetAutoLoad = @json(true);
</script>
<script src="{{ asset('Custom_js/shared/DatePresetFilter.js') }}?v={{ time() }}"></script>
<script src="{{ asset('Custom_js/Export/OmsetReport.js') }}?v={{ time() }}" defer></script>
@endpush
