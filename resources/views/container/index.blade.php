@extends('layouts.app')
@section('title', __('container.title'))

@section('topbar-actions')
@can('create container')
<a href="{{ route('container.create.page') }}" class="btn btn-accent btn-sm">
    <i class="bi bi-plus-lg me-1"></i>{{ __('container.new') }}
</a>
@endcan
@endsection

@section('content')
<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)">
            <i class="bi bi-truck me-2 text-accent"></i>{{ __('container.list_title') }}
        </h6>
        <div class="d-flex align-items-center gap-2">
            <span class="badge-glass" id="totalCount" style="color:var(--text-muted);font-size:.75rem">
                <i class="bi bi-hash me-1"></i><span id="countNum">0</span> {{ __('container.items_count_suffix') }}
            </span>
        </div>
    </div>

    <div class="filter-bar p-3 mb-3" style="border-bottom:1px solid var(--glass-border)">
        <div class="row g-2 align-items-end">
            <div class="col-lg-5 col-md-6">
                <label class="form-label mb-1"><i class="bi bi-search me-1"></i>{{ __('container.search') }}</label>
                <input type="text" id="filterSearch" class="form-control"
                       placeholder="{{ __('container.search_placeholder') }}">
            </div>
            <div class="col-lg-3 col-md-4">
                <label class="form-label mb-1"><i class="bi bi-funnel me-1"></i>{{ __('common.status') }}</label>
                <select id="filterStatus" class="form-select">
                    <option value="">{{ __('common.all_status') }}</option>
                    <option value="open">{{ __('container.status_open') }}</option>
                    <option value="loading">{{ __('container.status_loading') }}</option>
                    <option value="shipped">{{ __('container.status_shipped') }}</option>
                    <option value="arrived">{{ __('container.status_arrived') }}</option>
                    <option value="unloading">{{ __('container.status_unloading') }}</option>
                    <option value="closed">{{ __('container.status_closed') }}</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-2">
                <label class="form-label mb-1"><i class="bi bi-sort-alpha-down me-1"></i>{{ __('common.sort') }}</label>
                <select id="filterSort" class="form-select">
                    <option value="newest">{{ __('common.newest') }}</option>
                    <option value="oldest">{{ __('common.oldest') }}</option>
                    <option value="code-asc">{{ __('container.sort_party_asc') }}</option>
                    <option value="code-desc">{{ __('container.sort_party_desc') }}</option>
                    <option value="etd-asc">{{ __('container.sort_etd_asc') }}</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-12">
                <button class="btn btn-glass btn-sm w-100" id="btnResetFilter" style="height:42px">
                    <i class="bi bi-x-lg me-1"></i>{{ __('common.reset') }}
                </button>
            </div>
        </div>
        <div id="activeFilters" class="d-flex flex-wrap gap-1 mt-2" style="min-height:0"></div>
    </div>

    <div class="table-responsive">
        <table id="containerTable" class="table table-hover w-100">
            <thead>
                <tr>
                    <th>{{ __('container.col_china_wh') }}</th>
                    <th>{{ __('container.col_cont_no_bl') }}</th>
                    <th>{{ __('container.col_party_ctns') }}</th>
                    <th>{{ __('container.col_created_by') }}</th>
                    <th>{{ __('container.col_load_date') }}</th>
                    <th>{{ __('container.col_etd') }}</th>
                    <th>{{ __('container.col_eta') }}</th>
                    <th style="width:100px" class="text-center">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

@include('container._edit-modal')
@endsection

@push('scripts')
@php
    $containerI18n = \App\Support\JsTranslations::merge(['container', 'messages', 'common', 'swal']);
@endphp
<script>
    var token = "{{ csrf_token() }}";
    var _canDeleteContainer = @json(auth()->user()->hasRole('super_admin'));
    var _canEditContainer = @json(auth()->user()->can('edit container'));
    var _isSuperAdmin = @json(auth()->user()->hasRole('super_admin'));
    window._containerEditShowUrl = @json(route('container.show', ['container' => '__CONTAINER_ID__']));
    window._containerEditUpdateUrl = @json(route('container.update', ['container' => '__CONTAINER_ID__']));
    Object.assign(window.__t || {}, @json($containerI18n));
</script>
<script src="{{ asset('Custom_js/Container/Index.js') }}?v={{ time() }}" defer></script>
@endpush
