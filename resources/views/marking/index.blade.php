@extends('layouts.app')
@section('title', __('marking.title'))

@section('topbar-actions')
@can('create marking')
<button class="btn btn-accent btn-sm btnAdd">
    <i class="bi bi-plus-lg me-1"></i>{{ __('marking.add') }}
</button>
@endcan
@endsection

@section('content')
@if($isSalesScoped ?? false)
<div class="glass p-3 mb-4 d-flex align-items-center gap-2" style="border-left: 3px solid var(--accent);">
    <i class="bi bi-funnel-fill text-accent"></i>
    <span style="font-size:.85rem;color:var(--body-color)">{{ __('dashboard.sales_filter', ['count' => $scopedMarkingCount]) }}</span>
</div>
@endif
<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)">
            <i class="bi bi-bookmark-star me-2 text-accent"></i>{{ __('marking.list_title') }}
        </h6>
        <div class="d-flex align-items-center gap-2 marking-list-header-actions">
            <span class="badge-glass" id="totalCount" style="color:var(--text-muted);font-size:.75rem">
                <i class="bi bi-hash me-1"></i>{{ __('marking.count', ['count' => 0]) }}
            </span>
            @can('export marking')
            <div class="btn-group">
                <a href="#" class="btn btn-success btn-sm px-3" id="btnExportMarking" data-format="xlsx"
                   title="{{ __('marking.export_excel') }}">
                    <i class="bi bi-download me-1"></i><span>{{ __('marking.export') }}</span>
                </a>
                <button type="button" class="btn btn-success btn-sm dropdown-toggle dropdown-toggle-split"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">{{ __('marking.export_format') }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" id="btnExportMarkingCsv" data-format="csv">
                        <i class="bi bi-filetype-csv me-2"></i>{{ __('marking.export_csv') }}
                    </a></li>
                </ul>
            </div>
            @endcan
        </div>
    </div>

    <div class="filter-bar p-3 mb-3" style="border-bottom:1px solid var(--glass-border)">
        <div class="row g-2 marking-filter-row">
            <div class="col-lg col-md-6 marking-filter-field">
                <label class="form-label mb-1"><i class="bi bi-search me-1"></i>{{ __('marking.search_multi') }}</label>
                <input type="text" id="filterSearch" class="form-control"
                       placeholder="{{ __('marking.search_placeholder') }}">
            </div>
            <div class="col-lg col-md-4 marking-filter-field">
                <label class="form-label mb-1"><i class="bi bi-person-badge me-1"></i>{{ __('marking.filter_marketing') }}</label>
                <select id="filterMarketing" class="form-select">
                    <option value="">{{ __('marking.all_marketing') }}</option>
                    @foreach($salesUsers as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg col-md-4 marking-filter-field">
                <label class="form-label mb-1" for="filterPeriod"><i class="bi bi-calendar-range me-1"></i>{{ __('marking.filter_period') }}</label>
                <select id="filterPeriod" class="form-select">
                    <option value="">{{ __('common.date_preset_none') }}</option>
                    <option value="today">{{ __('common.date_preset_today') }}</option>
                    <option value="yesterday">{{ __('common.date_preset_yesterday') }}</option>
                    <option value="7d">{{ __('common.date_preset_7d') }}</option>
                    <option value="30d">{{ __('common.date_preset_30d') }}</option>
                    <option value="custom">{{ __('common.date_preset_custom') }}</option>
                </select>
            </div>
            <div class="col-lg col-md-4 marking-filter-field">
                <label class="form-label mb-1"><i class="bi bi-sort-alpha-down me-1"></i>{{ __('common.sort') }}</label>
                <select id="filterSort" class="form-select">
                    <option value="code-asc">{{ __('marking.sort_code_asc') }}</option>
                    <option value="code-desc">{{ __('marking.sort_code_desc') }}</option>
                    <option value="name-asc">{{ __('marking.sort_name_asc') }}</option>
                    <option value="name-desc">{{ __('marking.sort_name_desc') }}</option>
                    <option value="barangs-desc">{{ __('marking.sort_barangs_desc') }}</option>
                    <option value="barangs-asc">{{ __('marking.sort_barangs_asc') }}</option>
                </select>
            </div>
            <div class="col-lg col-md-6 marking-filter-field marking-filter-toggle">
                <label class="form-label mb-1 d-none d-lg-block">&nbsp;</label>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="toggleShowPrices">
                    <label class="form-check-label small" for="toggleShowPrices" style="color:var(--text-secondary)">
                        {{ __('marking.show_prices') }}
                    </label>
                </div>
            </div>
            <div class="col-lg-auto col-md-6 col-12 filter-actions">
                <label class="form-label mb-1 d-none d-lg-block">&nbsp;</label>
                <button type="button" class="btn btn-glass btn-sm px-3" id="btnResetFilter">
                    <i class="bi bi-x-lg"></i><span>{{ __('common.reset') }}</span>
                </button>
            </div>
        </div>
        <div id="filterPeriodCustomRow" class="row g-2 marking-filter-row d-none">
            <div class="col-lg col-md-4 marking-filter-field">
                <label class="form-label mb-1" for="filterPeriodFrom"><i class="bi bi-calendar-event me-1"></i>{{ __('barang.filter_tanggal_from') }}</label>
                <input type="date" id="filterPeriodFrom" class="form-control" autocomplete="off">
            </div>
            <div class="col-lg col-md-4 marking-filter-field">
                <label class="form-label mb-1" for="filterPeriodTo"><i class="bi bi-calendar-check me-1"></i>{{ __('barang.filter_tanggal_to') }}</label>
                <input type="date" id="filterPeriodTo" class="form-control" autocomplete="off">
            </div>
        </div>
        <div id="activeFilters" class="d-flex flex-wrap gap-1 mt-2" style="min-height:0"></div>
    </div>

    <div class="table-responsive">
        <table id="markingTable" class="table table-hover w-100">
            <thead>
                <tr>
                    <th>{{ __('marking.col_branch') }}</th>
                    <th>{{ __('marking.col_marketing') }}</th>
                    <th>{{ __('marking.col_code') }}</th>
                    <th class="col-address">{{ __('marking.col_address') }}</th>
                    <th>{{ __('marking.col_phone') }}</th>
                    <th class="col-price">{{ __('marking.col_price') }}</th>
                    <th style="width:120px">{{ __('common.actions') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="formModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-fullscreen-lg-down modal-dialog-centered modal-dialog-scrollable modal-marking">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-700" id="formModalTitle">
                    <i class="bi bi-bookmark-star me-2 text-accent"></i>{{ __('marking.add') }}
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="dataForm" enctype="multipart/form-data">
                <div class="modal-body">

                    <div id="markingRelationWrap" class="marking-relation-select-wrap mb-4">
                        <div class="marking-section-title"><i class="bi bi-diagram-3 me-2"></i>{{ __('marking.section_relation') }}</div>
                        <label class="form-label" for="relatedMarkingSearch">{{ __('marking.relation_field') }}</label>
                        <div class="marking-relation-picker">
                            <input type="hidden" id="inputRelatedMarkingId" name="related_marking_id" value="">
                            <div class="input-group">
                                <input type="text" id="relatedMarkingSearch" class="form-control" placeholder="{{ __('marking.relation_source_placeholder') }}" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary" id="btnClearRelatedMarking" title="{{ __('marking.relation_source_placeholder') }}" aria-label="Clear"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <div id="relatedMarkingResults" class="list-group marking-relation-results d-none"></div>
                            <div id="relatedMarkingSelected" class="small text-muted mt-1"></div>
                        </div>
                    </div>

                    <div class="marking-section-title"><i class="bi bi-bookmark-star me-2"></i>{{ __('marking.section_marking') }}</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('marking.label_customer') }} <span class="text-danger">*</span></label>
                            <input type="text" name="customer_name" id="inputCustomerName" class="form-control" maxlength="150" placeholder="{{ __('marking.placeholder_customer') }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('marking.label_code') }} <span class="text-danger">*</span></label>
                            <input type="text" name="code" class="form-control" required maxlength="50" placeholder="{{ __('marking.placeholder_code') }}" style="text-transform:uppercase">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('marking.label_sales') }}</label>
                            <select name="sales_id" class="form-select select2" id="selSales" data-placeholder="{{ __('marking.placeholder_sales') }}">
                                <option value="">{{ __('marking.placeholder_sales') }}</option>
                                @foreach($salesUsers as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('marking.hint_sales_supervisor') }}</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('marking.label_contact') }}</label>
                            <input type="text" name="contact_person" class="form-control" maxlength="100" placeholder="{{ __('marking.placeholder_contact') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('marking.label_email') }}</label>
                            <input type="email" name="email" class="form-control" placeholder="{{ __('marking.placeholder_email') }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ __('marking.label_pic') }}</label>
                            <input type="text" name="pic" class="form-control" maxlength="100" placeholder="{{ __('marking.placeholder_pic') }}">
                        </div>
                    </div>

                    
                    <div class="marking-section-title"><i class="bi bi-building me-2"></i>{{ __('marking.section_branch') }}</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('marking.section_branch') }}</label>
                            <select name="branch_id" class="form-select" id="selBranch">
                                <option value="">{{ __('marking.placeholder_branch') }}</option>
                                @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('marking.label_description') }}</label>
                            <input type="text" name="description" class="form-control" maxlength="255" placeholder="{{ __('marking.placeholder_description') }}">
                        </div>
                    </div>

                    
                    <div class="marking-section-title"><i class="bi bi-geo-alt me-2"></i>{{ __('marking.section_address') }}</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('marking.label_address_1') }}</label>
                            <textarea name="address_1" class="form-control" rows="2" placeholder="{{ __('marking.placeholder_address') }}"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('marking.label_address_2') }}</label>
                            <textarea name="address_2" class="form-control" rows="2" placeholder="{{ __('marking.placeholder_address_secondary') }}"></textarea>
                        </div>
                    </div>

                    
                    <div class="marking-section-title"><i class="bi bi-telephone me-2"></i>{{ __('marking.section_phone') }}</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">{{ __('marking.label_phone') }}</label>
                            <input type="text" name="phone_hp" class="form-control" maxlength="30" placeholder="{{ __('marking.placeholder_phone_hp') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('marking.label_fax') }}</label>
                            <input type="text" name="fax" class="form-control" maxlength="30" placeholder="{{ __('marking.placeholder_fax') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('marking.label_phone_02') }}</label>
                            <input type="text" name="phone_02" class="form-control" maxlength="30" placeholder="{{ __('marking.placeholder_phone_alt') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('marking.label_wa_name') }}</label>
                            <input type="text" name="wa_name" class="form-control" maxlength="100" placeholder="{{ __('marking.placeholder_wa') }}">
                        </div>
                    </div>

                    
                    <div class="marking-ktp-panel mb-4">
                        <div class="marking-section-title mb-0"><i class="bi bi-person-vcard me-2"></i>{{ __('marking.section_ktp') }}</div>
                        <div class="marking-ktp-panel-body">
                            <div class="marking-ktp-fields">
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label class="form-label">{{ __('marking.label_nik') }}</label>
                                        <input type="text" name="nik" class="form-control" id="inputNik" maxlength="30" placeholder="{{ __('marking.placeholder_nik') }}">
                                        <div class="nik-status" id="nikStatus"></div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label">{{ __('marking.label_ktp_name') }}</label>
                                        <input type="text" name="nik_name" class="form-control" maxlength="100" placeholder="{{ __('marking.placeholder_nik_name') }}">
                                    </div>
                                </div>
                            </div>
                            <div class="marking-ktp-photo-wrap">
                                <label class="form-label d-block">{{ __('marking.label_ktp_photo') }}</label>
                                <input type="hidden" name="remove_ktp_photo" id="removeKtpPhoto" value="0">
                                <div class="marking-ktp-photo-upload" id="ktpPhotoUpload">
                                    <input type="file" name="ktp_photo" id="inputKtpPhoto" class="marking-ktp-photo-input" accept="image/jpeg,image/png,image/webp">
                                    <div class="marking-ktp-photo-box" role="button" tabindex="0" aria-label="{{ __('marking.upload_ktp_photo') }}">
                                        <div class="marking-ktp-photo-empty">
                                            <i class="bi bi-cloud-arrow-up marking-ktp-photo-placeholder" aria-hidden="true"></i>
                                            <span class="marking-ktp-photo-empty-text">{{ __('marking.upload_ktp_photo') }}</span>
                                        </div>
                                        <img src="" class="marking-ktp-photo-preview d-none" alt="{{ __('marking.label_ktp_photo') }}">
                                        <button type="button" class="btn-remove-marking-ktp-photo d-none" aria-label="{{ __('common.remove_file') }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <a href="#" target="_blank" rel="noopener" class="marking-ktp-photo-view-link d-none" id="ktpPhotoViewLink">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>{{ __('marking.view_current_ktp_photo') }}
                                </a>
                                <div class="form-text small marking-ktp-photo-hint">{{ __('marking.hint_ktp_photo') }} Foto otomatis dikompres saat dipilih agar upload lebih cepat.</div>
                            </div>
                        </div>
                    </div>

                    <div class="marking-section-title"><i class="bi bi-shield-lock me-2"></i>{{ __('marking.section_passcode') }}</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">{{ __('marking.label_passcode') }}</label>
                            <input type="text" name="passcode" class="form-control" maxlength="20" placeholder="{{ __('marking.placeholder_passcode') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('marking.label_priority') }}</label>
                            <select name="priority" class="form-select" id="selPriority">
                                <option value="non_vip">{{ __('marking.priority_non_vip') }}</option>
                                <option value="vip">{{ __('marking.priority_vip') }}</option>
                            </select>
                        </div>
                    </div>

                    
                    @can('manage marking pricing')
                    <div class="marking-section-title"><i class="bi bi-sliders me-2"></i>{{ __('marking.section_settings') }}</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">{{ __('marking.label_effective_start') }}</label>
                            <input type="date" name="effective_start" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('marking.label_invoice_due') }}</label>
                            <input type="number" name="invoice_due_days" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('marking.label_max_weight_kg') }}</label>
                            <input type="number" name="max_weight_kg" class="form-control" step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('marking.label_min_charge') }}</label>
                            <input type="number" name="minimum_charge" class="form-control" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                        </div>
                    </div>

                    <div class="marking-section-title"><i class="bi bi-currency-dollar me-2"></i>{{ __('marking.section_pricing') }}</div>
                    <div id="pricingContainer" class="mb-3">
                        
                    </div>
                    <button type="button" class="btn btn-glass btn-sm" id="btnAddPricing">
                        <i class="bi bi-plus-lg me-1"></i>{{ __('marking.add_pricing') }}
                    </button>
                    @endcan

                </div>
            </form>
            <div class="modal-footer">
                <button type="button" class="btn btn-glass" data-bs-dismiss="modal">{{ __('common.cancel') }}</button>
                @canany(['create marking', 'edit marking'])
                <button type="submit" class="btn btn-accent" id="btnSave" form="dataForm">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="btnSpinner"></span>
                    <i class="bi bi-check-lg me-1"></i>{{ __('common.save') }}
                </button>
                @endcanany
            </div>
        </div>
    </div>
</div>


<template id="pricingRowTemplate">
    <div class="pricing-row glass-solid p-3 mb-2" style="border-radius:var(--radius-xl)">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-600 small" style="color:var(--text-muted)">{{ __('marking.pricing_row') }}</span>
            <button type="button" class="btn btn-sm text-danger btnRemovePricing" title="{{ __('common.delete') }}"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">{{ __('marking.label_item') }}</label>
                <select name="p_pricing_item_id" class="form-select select2 pricing-item-select" data-placeholder="{{ __('marking.placeholder_select_item') }}">
                    <option value=""></option>
                    @foreach($pricingItems as $pi)
                    <option value="{{ $pi->id }}">{{ $pi->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('marking.label_item_name') }}</label>
                <textarea name="p_item_name" class="form-control pricing-item-name-input" rows="2" maxlength="2000" autocomplete="off" placeholder="{{ __('marking.placeholder_item_name') }}"></textarea>
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('marking.label_volume_price') }}</label>
                <input type="text" name="p_volume_price" class="form-control mask-currency pricing-volume-input" inputmode="numeric" autocomplete="off" placeholder="0">
            </div>
            <div class="col-md-3">
                <label class="form-label">{{ __('marking.label_gw_price') }}</label>
                <input type="text" name="p_gw_price" class="form-control mask-currency pricing-gw-input" inputmode="numeric" autocomplete="off" placeholder="0">
            </div>
        </div>
    </div>
</template>
@endsection

@push('scripts')
@php
    $markingI18n = \App\Support\JsTranslations::merge(['marking', 'common', 'swal']);
    $masterPricingMap = $pricingItems->mapWithKeys(static fn ($pi) => [
        $pi->id => [
            'id' => $pi->id,
            'name' => $pi->name,
            'volume_price' => $pi->volume_price,
            'gw_price' => $pi->gw_price,
        ],
    ]);
@endphp
<script>
    Object.assign(window.__t || {}, @json($markingI18n));
    window.markingMasterPricingItems = @json($masterPricingMap);
    var token = "{{ csrf_token() }}";
    var _canEditMarking = @json(auth()->user()->can('edit marking'));
    var _canDeleteMarking = @json(auth()->user()->can('delete marking'));
    var markingExportUrl = @json(route('marking.export'));
</script>
<script src="{{ asset('Custom_js/shared/DatePresetFilter.js') }}?v={{ time() }}"></script>
<script src="{{ asset('Custom_js/Marking/Index.js') }}?v={{ time() }}" defer></script>
@endpush