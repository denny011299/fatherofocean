@extends('layouts.app')
@section('title', __('container.create_title'))

@section('topbar-actions')
<a href="{{ route('container.index') }}" class="btn btn-glass btn-sm">
    <i class="bi bi-arrow-left me-1"></i>{{ __('container.back_to_list') }}
</a>
@endsection

@section('content')
<form id="containerCreateForm" class="form-page-with-sticky-footer">
    <div class="glass p-4 mb-4">
        <div class="mb-4">
            <h5 class="fw-800 mb-1" style="color:var(--text-primary)">
                <i class="bi bi-truck me-2 text-accent"></i>{{ __('container.create_title') }}
            </h5>
            <div class="small" style="color:var(--text-muted)">
                {{ __('container.create_intro') }}
            </div>
        </div>

        <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
            <i class="bi bi-card-list me-2 text-accent"></i>{{ __('container.section_detail') }}
        </h6>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_party') }} <span class="text-danger">*</span></label>
                <input type="text" name="partai" class="form-control" placeholder="{{ __('container.placeholder_party_code') }}" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_container_no') }}</label>
                <input type="text" name="container_no" class="form-control" placeholder="{{ __('container.placeholder_container_no') }}">
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_no_bl') }}</label>
                <input type="text" name="no_bl" class="form-control" placeholder="{{ __('container.placeholder_no_bl') }}">
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_gudang_china') }}</label>
                <select name="gudang_china_id" class="form-select select2" data-placeholder="{{ __('container.placeholder_select_china_wh') }}">
                    <option value="">{{ __('container.placeholder_select_china_wh') }}</option>
                    @foreach($gudangList as $gudang)
                    <option value="{{ $gudang->id }}">{{ $gudang->name }}{{ $gudang->address ? ' — ' . $gudang->address : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_route') }}</label>
                <select name="route" class="form-select select2" data-placeholder="{{ __('container.placeholder_select_route') }}">
                    <option value="">{{ __('container.placeholder_select_route') }}</option>
                    @foreach($routes as $route)
                    <option value="{{ $route->name }}">{{ $route->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('container.label_load_date') }}</label>
                <input type="date" name="load_date" class="form-control" value="{{ date('Y-m-d') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('container.label_etd') }}</label>
                <input type="date" name="etd" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('container.label_eta') }}</label>
                <input type="date" name="eta" class="form-control">
            </div>
            <div class="col-12">
                <label class="form-label">{{ __('container.label_length') }} <span class="text-danger">*</span></label>
                <select name="type" class="form-select select2" id="containerType" data-placeholder="{{ __('container.placeholder_select_type') }}" required>
                    @foreach($containerTypes as $ct)
                    <option value="{{ $ct->code }}" data-l="{{ $ct->length }}" data-w="{{ $ct->width }}" data-h="{{ $ct->height }}" data-v="{{ $ct->volume }}">
                        {{ __('container.type_dimensions', ['label' => $ct->label, 'l' => $ct->length, 'w' => $ct->width, 'h' => $ct->height, 'volume' => $ct->volume]) }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <div class="glass-solid p-3">
                    <div class="row g-2 text-center">
                        <div class="col-3"><div class="small" style="color:var(--text-muted);font-size:.6rem">{{ __('container.spec_p') }}</div><div class="fw-700" style="color:var(--text-primary)" id="specL">-</div></div>
                        <div class="col-3"><div class="small" style="color:var(--text-muted);font-size:.6rem">{{ __('container.spec_l') }}</div><div class="fw-700" style="color:var(--text-primary)" id="specW">-</div></div>
                        <div class="col-3"><div class="small" style="color:var(--text-muted);font-size:.6rem">{{ __('container.spec_t') }}</div><div class="fw-700" style="color:var(--text-primary)" id="specH">-</div></div>
                        <div class="col-3"><div class="small" style="color:var(--text-muted);font-size:.6rem">{{ __('container.max_volume') }}</div><div class="fw-800 text-accent" id="specV">-</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_department') }}</label>
                <select name="department_item" class="form-select select2" data-placeholder="{{ __('container.placeholder_select_department') }}">
                    <option value="">{{ __('container.placeholder_select_department') }}</option>
                    @foreach($departments as $dept)
                    <option value="{{ $dept->name }}">{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_port') }}</label>
                <select name="port_id" class="form-select select2" data-placeholder="{{ __('container.placeholder_select_port') }}">
                    <option value="">{{ __('container.placeholder_select_port') }}</option>
                    @foreach($ports as $port)
                    <option value="{{ $port->id }}">{{ $port->name }}@if($port->city) — {{ $port->city }}@endif</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_insurance_value') }}</label>
                <input type="number" name="insurance_value" class="form-control" placeholder="{{ __('container.placeholder_insurance_value') }}" value="0" step="0.01">
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('container.label_insurance_rate') }}</label>
                <div class="input-group">
                    <input type="number" name="insurance_rate" class="form-control" placeholder="{{ __('container.placeholder_rate') }}" value="0" step="0.01">
                    <span class="input-group-text">%</span>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('container.label_insurance_date') }}</label>
                <input type="date" name="insurance_date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('container.label_gate_pass_date') }}</label>
                <input type="date" name="gate_pass_date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('container.label_notes') }}</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="{{ __('container.placeholder_notes') }}"></textarea>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="glass p-4 h-100">
                <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
                    <i class="bi bi-bookmark-star me-2 text-accent"></i>{{ __('container.estimation_via_marking') }}
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
                    <div class="mt-2 small" style="color:var(--text-muted)">{{ __('container.preview_empty_create') }}</div>
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
                            <div style="font-size:.65rem;color:var(--text-muted)">{{ __('container.volume_staging') }}</div>
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
            @can('create container')
            <button type="button" class="btn btn-accent" id="btnSaveContainer">
                <i class="bi bi-check-lg me-1"></i>{{ __('common.save') }}
            </button>
            @endcan
        </div>
    </div>
</form>
@endsection

@push('scripts')
@php
    $containerI18n = \App\Support\JsTranslations::merge(['container', 'messages', 'common', 'swal']);
@endphp
<script>
    Object.assign(window.__t || {}, @json($containerI18n));
    var _containerCreateConfig = {
        csrf: '{{ csrf_token() }}',
        storeUrl: @json(route('container.store')),
        markingPreviewUrl: @json(route('container.marking-preview-draft')),
        indexUrl: @json(route('container.index')),
    };
</script>
<script src="{{ asset('Custom_js/shared/DatePresetFilter.js') }}?v={{ time() }}"></script>
<script src="{{ asset('Custom_js/Container/MarkingEstimationShared.js') }}?v={{ time() }}"></script>
<script src="{{ asset('Custom_js/Container/Create.js') }}?v={{ time() }}" defer></script>
@endpush
