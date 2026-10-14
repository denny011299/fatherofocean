@extends('layouts.app')
@section('title', __('bongkar_muat.list_title'))

@section('topbar-actions')
@can('manage bongkar muat')
<a href="{{ route('bongkar-muat.create') }}" class="btn btn-accent btn-sm">
    <i class="bi bi-plus-circle-fill me-1"></i>{{ __('bongkar_muat.add_unload') }}
</a>
@endcan
@endsection

@section('content')
<div class="glass p-4">
    <div class="d-flex align-items-center gap-2 mb-3">
        <div class="stat-icon-squircle squircle-blue" style="width:38px;height:38px;font-size:1.1rem;border-radius:12px;">
            <i class="bi bi-box-arrow-in-down"></i>
        </div>
        <div>
            <h5 class="fw-700 mb-0" style="color:var(--text-primary)">{{ __('bongkar_muat.list_title') }}</h5>
            <div style="font-size:0.75rem;color:var(--text-muted)">{{ __('bongkar_muat.list_subtitle') }}</div>
        </div>
    </div>

    <div class="filter-bar p-3 mb-3" style="border-bottom:1px solid var(--glass-border)">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <input type="text" id="filterSearch" class="form-control" placeholder="{{ __('bongkar_muat.search_placeholder') }}">
            </div>
            <div class="col-md-3">
                <select id="filterStatus" class="form-select">
                    <option value="">{{ __('common.all_status') }}</option>
                    <option value="completed">{{ __('bongkar_muat.status_completed') }}</option>
                    <option value="in_progress">{{ __('bongkar_muat.status_in_progress') }}</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-glass w-100" id="btnResetFilter">{{ __('common.reset') }}</button>
            </div>
        </div>
    </div>

    <table class="table-glass w-100" id="bongkarMuatTable">
        <thead>
            <tr>
                <th>{{ __('bongkar_muat.col_partai') }}</th>
                <th>{{ __('bongkar_muat.col_container') }}</th>
                <th>{{ __('common.status') }}</th>
                <th>{{ __('bongkar_muat.col_scanned') }}</th>
                <th>{{ __('bongkar_muat.col_by') }}</th>
                <th>{{ __('bongkar_muat.col_date') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
@endsection

@push('styles')
<style>
.bm-actions {
    display: flex;
    justify-content: flex-end;
    gap: .4rem;
}
.bm-action-btn {
    width: 34px;
    height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: .8rem;
}
</style>
@endpush

@push('scripts')
@php $dtI18n = \App\Support\JsTranslations::merge(['bongkar_muat', 'common', 'container', 'swal']); @endphp
<script>Object.assign(window.__t || {}, @json($dtI18n));</script>
<script>
    window.__bongkarMuatListConfig = {
        dataUrl: @json(route('bongkar-muat.data')),
        createUrl: @json(route('bongkar-muat.create')),
        deleteTpl: @json(url('/bongkar-muat/session/__SID__')),
        canManage: @json(auth()->user()->can('manage bongkar muat')),
    };
</script>
<script src="{{ asset('Custom_js/BongkarMuat/List.js') }}?v={{ time() }}" defer></script>
@endpush
