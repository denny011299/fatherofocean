@extends('layouts.app')
@section('title', $group . ' — ' . $title)

@php
    $isPromoPage = $type === 'promos';
    $isIncentivePage = $type === 'sales-incentive-tiers';
    $canManageIncentive = auth()->user()->can('manage sales incentive master') || auth()->user()->can('manage master data');
    $canCreateMaster = $isPromoPage
        ? (auth()->user()->can('create promo master') || auth()->user()->can('manage master data'))
        : ($isIncentivePage ? $canManageIncentive : auth()->user()->can('manage master data'));
    $canEditMaster = $isPromoPage
        ? (auth()->user()->can('edit promo master') || auth()->user()->can('manage master data'))
        : ($isIncentivePage ? $canManageIncentive : auth()->user()->can('manage master data'));
@endphp

@section('topbar-actions')
@if($canCreateMaster)
<button class="btn btn-accent btn-sm btnAdd">
    <i class="bi bi-plus-circle-fill me-1"></i>{{ __('common.create') }}
</button>
@endif
@endsection

@section('content')
@if(!empty($isIncentiveMaster))
<p class="small mb-3" style="color:var(--text-muted)">
    <i class="bi bi-info-circle me-1"></i>{{ __('master_data.incentive_master_hint') }}
    @if($canManageIncentive)
    · <a href="{{ route('master-data.incentive.recalc') }}">{{ __('master_data.incentive_recalc_link') }}</a>
    @endif
</p>
@if(($modalPricingItemCount ?? 0) === 0)
<div class="alert alert-warning py-2 small mb-3">
    <i class="bi bi-exclamation-triangle me-1"></i>{{ __('master_data.incentive_no_modal_categories') }}
</div>
@endif
@endif
<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <div class="stat-icon-squircle squircle-royal" style="width:38px;height:38px;font-size:1.1rem;border-radius:12px;">
                <i class="bi {{ $icon }}"></i>
            </div>
            <div>
                <h5 class="fw-700 mb-0" style="color:var(--text-primary)">{{ $title }}</h5>
                <div style="font-size:0.75rem;color:var(--text-muted)">
                    @unless(!empty($isIncentiveMaster))
                    <span style="opacity:.7;font-weight:600">{{ $group }}</span> ·
                    @endunless
                    Kelola data master kargo dan operasional
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="masterTable" class="table-glass w-100">
            <thead>
                <tr>
                    @foreach($columns as $col)
                    <th>{{ $col['label'] }}</th>
                    @endforeach
                    <th style="width:80px">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

{{-- Modal Form --}}
<div class="modal fade" id="formModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-700" id="formModalTitle">{{ __('common.add_data') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="dataForm">
                <div class="modal-body">
                    @foreach($fields as $f)
                    <div class="mb-3{{ !empty($f['promo_show_if_type']) ? ' promo-field-group' : '' }}" @if(!empty($f['promo_show_if_type'])) data-promo-show-type="{{ $f['promo_show_if_type'] }}" @endif>
                        <label class="form-label">{{ $f['label'] }} @if(!empty($f['required']))<span class="text-danger">*</span>@endif</label>

                        @if(($f['type'] ?? 'text') === 'parent-select')
                            <select name="{{ $f['name'] }}" class="form-select select2" {{ !empty($f['required']) ? 'required' : '' }}>
                                @if(!empty($f['include_all']))
                                <option value="all">{{ __('common.all') }}</option>
                                @else
                                <option value="">{{ __('common.select_placeholder') }}</option>
                                @endif
                                @foreach($parentData[$f['source']] ?? [] as $item)
                                <option value="{{ $item->id }}">{{ $item->name }}{{ isset($item->code) ? ' ('.$item->code.')' : '' }}{{ isset($item->country) ? ' — '.$item->country->name : '' }}{{ isset($item->province) ? ' — '.$item->province->name : '' }}</option>
                                @endforeach
                            </select>
                            @if(!empty($f['hint']))
                            <div class="form-text">{{ $f['hint'] }}</div>
                            @endif
                        @elseif(($f['type'] ?? 'text') === 'select')
                            <select name="{{ $f['name'] }}" class="form-select{{ $isPromoPage && $f['name'] === 'type' ? ' promo-type-select' : '' }}" {{ !empty($f['required']) ? 'required' : '' }}>
                                @foreach($f['options'] ?? [] as $optValue => $optLabel)
                                <option value="{{ is_int($optValue) ? $optLabel : $optValue }}">{{ $optLabel }}</option>
                                @endforeach
                            </select>
                        @elseif(($f['type'] ?? 'text') === 'textarea')
                            <textarea name="{{ $f['name'] }}" class="form-control" {{ !empty($f['required']) ? 'required' : '' }} rows="3" maxlength="{{ $f['max'] ?? 255 }}"></textarea>
                        @else
                            <div class="{{ !empty($f['promo_show_if_type']) ? '' : '' }}">
                            <input type="{{ $f['type'] ?? 'text' }}" name="{{ $f['name'] }}" class="form-control"
                                {{ !empty($f['required']) ? 'required' : '' }}
                                {{ isset($f['max']) ? 'maxlength='.$f['max'] : '' }}
                                {{ isset($f['step']) ? 'step='.$f['step'] : '' }}
                                @if(!empty($f['placeholder'])) placeholder="{{ $f['placeholder'] }}" @endif
                                {{ isset($f['min']) ? 'min='.$f['min'] : '' }}>
                            </div>
                        @endif
                    </div>
                    @endforeach
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                    @if($canEditMaster)
                    <button type="submit" class="btn btn-accent" id="btnSave">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="btnSpinner"></span>{{ __('common.save') }}
                    </button>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@php
    $masterDataI18n = \App\Support\JsTranslations::merge(['master_data', 'common', 'swal']);
@endphp
<script>
    Object.assign(window.__t || {}, @json($masterDataI18n));
    var token = "{{ csrf_token() }}";
    var PAGE_TYPE = "{{ $type }}";
    var PAGE_COLUMNS = {!! json_encode($columns) !!};
    var PAGE_FIELDS = {!! json_encode($fields) !!};
    var _canManageMasterData = @json($canEditMaster);
    var _canDeleteMasterData = @json($isPromoPage
        ? (auth()->user()->can('delete promo master') || auth()->user()->can('manage master data'))
        : ($isIncentivePage ? $canManageIncentive : auth()->user()->can('manage master data')));
    var PAGE_HAS_TOGGLE = @json($hasToggle ?? false);
</script>
<script src="{{ asset('Custom_js/MasterData/Index.js') }}?v={{ time() }}" defer></script>
@endpush
