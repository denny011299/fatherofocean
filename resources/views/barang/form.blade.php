@extends('layouts.app')
@section('title', isset($barang) ? __('barang.edit_title') : __('barang.create_title'))

@section('topbar-actions')
<a href="{{ route('barang.index') }}" class="btn btn-glass btn-sm">
    <i class="bi bi-arrow-left me-1"></i>{{ __('barang.back_to_list') }}
</a>
@endsection

@section('content')
<form id="barangForm" class="form-page-with-sticky-footer" enctype="multipart/form-data">
    <div class="glass p-4 mb-4">
        <div class="mb-4">
            <h5 class="fw-800 mb-1" style="color:var(--text-primary)">
                <i class="bi bi-box-seam-fill me-2 text-accent"></i>
                {{ isset($barang) ? __('barang.edit_title') : __('barang.create_title') }}
            </h5>
            <div class="small" style="color:var(--text-muted)">
                {{ isset($barang) ? __('barang.edit_subtitle') : __('barang.create_subtitle') }}
            </div>
        </div>

        @include('barang._form')
    </div>

    <div class="form-sticky-footer">
        <div class="form-sticky-footer-inner">
            <a href="{{ route('barang.index') }}" class="btn btn-glass" id="btnCancelBarang">
                <i class="bi bi-x-lg me-1"></i>{{ __('common.cancel') }}
            </a>
            @can('view barang')
            <a href="#" id="btnExportOriginalPl" class="btn btn-glass d-none" target="_blank" rel="noopener">
                <i class="bi bi-download me-1"></i>{{ __('barang.export_original_pl') }}
            </a>
            @endcan
            @if(isset($barang))
                @can('export barang')
                <a href="{{ route('barang.products.export-excel', $barang) }}" class="btn btn-glass" id="btnExportProductsExcel" target="_blank" rel="noopener"
                   title="{{ __('barang.tooltip_export_excel') }}">
                    <i class="bi bi-file-earmark-excel me-1" style="color:#16a34a"></i>{{ __('barang.export_products_excel') }}
                </a>
                <a href="{{ route('barang.terima-barang.pdf', $barang) }}" class="btn btn-glass" id="btnExportTerimaPdf" target="_blank" rel="noopener"
                   title="{{ __('barang.tooltip_export_terima_barang') }}">
                    <i class="bi bi-file-earmark-pdf me-1" style="color:#ef4444"></i>{{ __('barang.export_terima_barang_pdf') }}
                </a>
                @endcan
            @else
                @can('export barang')
                <a href="#" class="btn btn-glass d-none" id="btnExportProductsExcel" target="_blank" rel="noopener"
                   title="{{ __('barang.tooltip_export_excel') }}">
                    <i class="bi bi-file-earmark-excel me-1" style="color:#16a34a"></i>{{ __('barang.export_products_excel') }}
                </a>
                <a href="#" class="btn btn-glass d-none" id="btnExportTerimaPdf" target="_blank" rel="noopener"
                   title="{{ __('barang.tooltip_export_terima_barang') }}">
                    <i class="bi bi-file-earmark-pdf me-1" style="color:#ef4444"></i>{{ __('barang.export_terima_barang_pdf') }}
                </a>
                @endcan
            @endif
            @can('export barang')
            <a href="{{ isset($barang) ? route('barang.shipping-label.pdf', $barang) : '#' }}"
               class="btn btn-glass {{ isset($barang) ? '' : 'd-none' }}"
               id="btnExportShippingLabel"
               target="_blank"
               rel="noopener"
               title="{{ __('barang.tooltip_export_shipping_label') }}">
                <i class="bi bi-upc-scan me-1" style="color:var(--accent)"></i>{{ __('barang.export_shipping_label') }}
            </a>
            @endcan
            @canany(['create barang', 'edit barang'])
            <button type="button" class="btn btn-accent" id="btnSaveBarang">
                <span class="spinner-border spinner-border-sm d-none me-1" id="btnSaveBarangSpinner" role="status" aria-hidden="true"></span>
                <i class="bi bi-save me-1" id="btnSaveBarangIcon"></i><span id="btnSaveBarangLabel">{{ __('common.save') }}</span>
            </button>
            @endcanany
        </div>
    </div>
</form>

<div class="modal fade" id="dimensionProofModal" tabindex="-1" aria-labelledby="dimensionProofModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-700" id="dimensionProofModalLabel">{{ __('barang.dimension_proof_modal_title') }}</h5>
                <button type="button" class="btn-close btn-close-white" id="btnDimensionProofModalClose" data-bs-dismiss="modal" aria-label="{{ __('common.close') }}"></button>
            </div>
            <div class="modal-body">
                <div class="dimension-proof-preview-wrap mb-3">
                    <img src="" alt="" id="dimensionProofPreview" class="dimension-proof-preview-img d-none">
                    <div id="dimensionProofEmpty" class="dimension-proof-empty text-muted small text-center">
                        <i class="bi bi-image dimension-proof-empty-icon" aria-hidden="true"></i>
                        <div class="mt-1">{{ __('barang.dimension_proof_empty') }}</div>
                    </div>
                </div>
                <div id="dimensionProofProgressWrap" class="dimension-proof-progress-wrap d-none mb-3" aria-live="polite">
                    <div class="d-flex justify-content-between align-items-center small text-muted mb-1">
                        <span id="dimensionProofProgressLabel">{{ __('barang.dimension_proof_uploading') }}</span>
                        <span id="dimensionProofProgressPct" class="fw-600">0%</span>
                    </div>
                    <div class="progress dimension-proof-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div id="dimensionProofProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"></div>
                    </div>
                </div>
                <label class="form-label small text-muted mb-1" for="dimensionProofModalFile">{{ __('barang.dimension_proof_upload') }}</label>
                <input type="file" class="form-control form-control-sm" id="dimensionProofModalFile" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-glass text-danger" id="btnDimensionProofRemove">
                    <i class="bi bi-trash me-1"></i>{{ __('barang.dimension_proof_remove') }}
                </button>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-sm btn-glass" id="btnDimensionProofClose" data-bs-dismiss="modal">{{ __('common.close') }}</button>
                    <button type="button" class="btn btn-sm btn-accent" id="btnDimensionProofSave">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="btnDimensionProofSaveSpinner" role="status" aria-hidden="true"></span>
                        <i class="bi bi-save me-1" id="btnDimensionProofSaveIcon"></i>{{ __('common.save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
#barangForm .select2-container .select2-selection.is-invalid {
    border-color: var(--danger, #fb7185) !important;
}
#barangFormErrors {
    border: 1px solid rgba(251, 113, 133, .35);
    background: rgba(251, 113, 133, .08);
    color: var(--text-primary);
}
.product-scroll-wrap {
    overflow-x: auto;
    border: 1px solid var(--glass-border);
    border-radius: .75rem;
    padding: .5rem;
}
#barangForm .barang-marking-price-field[readonly],
#barangForm .barang-daily-rate-field[readonly],
#barangForm .barang-product-logistics-locked[readonly] {
    background: var(--input-bg);
    color: var(--text-muted);
    cursor: not-allowed;
    opacity: .92;
}
#barangForm .barang-marking-price-field.barang-marking-price-unset {
    font-style: italic;
}
/* Good receipt — label atas, kotak bawah */
#barangForm .good-receipt-field {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    max-width: 100%;
}
#barangForm .good-receipt-field > .form-label {
    display: block;
    width: 100%;
    margin-bottom: .5rem;
}
#barangForm .good-receipt-hint {
    margin-top: .45rem;
    color: var(--text-muted);
}
#barangForm .good-receipt-upload {
    position: relative;
    display: block;
}
#barangForm .good-receipt-input {
    position: absolute !important;
    width: 0 !important;
    height: 0 !important;
    opacity: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
    clip: rect(0, 0, 0, 0);
    border: 0;
    padding: 0;
    margin: 0;
}
#barangForm .good-receipt-box {
    width: 88px;
    height: 88px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    background: var(--input-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
    transition: border-color .2s, background .2s, box-shadow .2s;
}
#barangForm .good-receipt-box:hover,
#barangForm .good-receipt-box:focus-visible {
    border-color: rgba(var(--accent-rgb), .55);
    background: rgba(var(--accent-rgb), .06);
    box-shadow: 0 0 0 2px rgba(var(--accent-rgb), .12);
    outline: none;
}
#barangForm .good-receipt-placeholder {
    font-size: 2rem;
    font-weight: 300;
    line-height: 1;
    color: var(--text-muted);
    pointer-events: none;
    user-select: none;
    z-index: 0;
}
#barangForm .good-receipt-box:hover .good-receipt-placeholder,
#barangForm .good-receipt-box:focus-visible .good-receipt-placeholder {
    color: var(--accent);
}
#barangForm .good-receipt-preview {
    position: absolute;
    inset: 0;
    z-index: 1;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
}
#barangForm .good-receipt-upload.has-photo .good-receipt-box {
    border-color: rgba(var(--accent-rgb), .28);
}
#barangForm .good-receipt-upload.has-photo .good-receipt-placeholder,
#barangForm .good-receipt-upload.has-photo .good-receipt-placeholder.d-none {
    display: none !important;
}
#barangForm .good-receipt-upload:not(.has-photo) .good-receipt-preview {
    display: none !important;
}
#barangForm .btn-remove-good-receipt {
    position: absolute;
    top: 3px;
    right: 3px;
    z-index: 2;
    width: 22px;
    height: 22px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(0, 0, 0, .62);
    color: #fff;
    font-size: .7rem;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
#barangForm .btn-remove-good-receipt:hover {
    background: var(--danger);
}
/* Product photo — sama seperti good receipt: foto atau kotak + */
#barangForm .product-photo-cell { vertical-align: middle; width: 88px; }
#barangForm .product-photo-upload { position: relative; display: inline-block; }
#barangForm .product-photo-upload.is-uploading { opacity: 0.6; pointer-events: none; }
#barangForm .product-add-row-tr td {
    border-top: 1px dashed var(--glass-border);
    background: var(--btn-glass-bg, rgba(0, 0, 0, 0.02));
}
#barangForm .product-photo-input {
    position: absolute !important; width: 0 !important; height: 0 !important;
    opacity: 0 !important; overflow: hidden !important; pointer-events: none !important;
}
#barangForm .product-photo-box {
    width: 72px; height: 72px; border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg); background: var(--input-bg);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; position: relative; overflow: hidden;
}
#barangForm .product-photo-placeholder {
    font-size: 2rem; font-weight: 300; line-height: 1; color: var(--text-muted);
    pointer-events: none; user-select: none; z-index: 0;
}
#barangForm .product-photo-box:hover .product-photo-placeholder,
#barangForm .product-photo-box:focus-visible .product-photo-placeholder {
    color: var(--accent);
}
#barangForm .product-photo-preview {
    position: absolute; inset: 0; z-index: 1; width: 100%; height: 100%;
    object-fit: cover; border-radius: inherit;
}
#barangForm .product-photo-upload.has-photo .product-photo-placeholder { display: none !important; }
#barangForm .product-photo-upload:not(.has-photo) .product-photo-preview { display: none !important; }
#barangForm .btn-remove-product-photo {
    position: absolute; top: 3px; right: 3px; z-index: 2;
    width: 22px; height: 22px; padding: 0; border: none; border-radius: 50%;
    background: rgba(0,0,0,.62); color: #fff; font-size: .7rem;
    display: flex; align-items: center; justify-content: center; cursor: pointer;
}
#barangForm .btn-remove-product-photo.d-none { display: none !important; }
#barangForm .dimension-input-wrap {
    display: flex; align-items: stretch; gap: 4px; min-width: 92px;
}
#barangForm .dimension-input-wrap .form-control { min-width: 0; flex: 1 1 auto; padding-left: .35rem; padding-right: .25rem; }
#barangForm .btn-dimension-proof {
    flex: 0 0 auto; align-self: center;
    width: 32px; height: 32px; padding: 0;
    border: 1px solid var(--glass-border); border-radius: .5rem;
    background: var(--input-bg); color: var(--text-muted); line-height: 1;
    display: inline-flex; align-items: center; justify-content: center;
    transition: color .15s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
}
#barangForm .btn-dimension-proof .bi { font-size: 1.05rem; }
#barangForm .btn-dimension-proof:hover,
#barangForm .btn-dimension-proof:focus-visible {
    color: var(--accent); border-color: rgba(129, 140, 248, .35);
    background: rgba(129, 140, 248, .08);
    box-shadow: 0 0 0 2px rgba(129, 140, 248, .12);
}
#barangForm .btn-dimension-proof.has-proof {
    color: var(--accent); border-color: rgba(129, 140, 248, .45);
    background: rgba(129, 140, 248, .12);
}
.dimension-proof-preview-wrap {
    border: 1px dashed var(--glass-border); border-radius: 1rem;
    min-height: 320px; display: flex; align-items: center; justify-content: center;
    overflow: hidden; background: var(--input-bg);
}
.dimension-proof-empty { padding: 2.5rem 1rem; }
.dimension-proof-empty-icon { font-size: 2.75rem; opacity: .35; display: block; margin: 0 auto; }
.dimension-proof-preview-img { max-width: 100%; max-height: 420px; width: auto; object-fit: contain; }
.dimension-proof-progress-wrap .dimension-proof-progress { height: 8px; border-radius: 999px; background: var(--input-bg); }
.dimension-proof-progress-wrap .progress-bar { background: linear-gradient(90deg, var(--accent), #a5b4fc); }
</style>
@endpush

@push('scripts')
@php
    $barangPricingI18n = array_merge(
        [
            'method_cbm_default' => __('barang.pricing_method_cbm_default'),
            'method_cbm' => __('barang.pricing_method_cbm'),
            'method_weight' => __('barang.pricing_method_weight'),
            'method_split' => __('barang.pricing_method_split'),
            'method_cbm_min' => __('barang.pricing_method_cbm_min'),
            'add' => __('barang.add'),
            'edit' => __('barang.edit'),
        ],
        \App\Support\JsTranslations::group('barang')
    );
@endphp
<script>
    var token = "{{ csrf_token() }}";
    var countUnitsOptions = @json($countUnits->pluck('name'));
    var barangPricingI18n = @json($barangPricingI18n);
    window.__t = window.__t || {};
    if (typeof Object.assign === 'function') {
        Object.assign(window.__t, barangPricingI18n);
    } else {
        for (var _bk in barangPricingI18n) {
            if (Object.prototype.hasOwnProperty.call(barangPricingI18n, _bk)) {
                window.__t[_bk] = barangPricingI18n[_bk];
            }
        }
    }
    var _barangFormConfig = {
        isEdit: @json(isset($barang)),
        barangId: @json(isset($barang) ? $barang->id : null),
        storeUrl: @json(route('barang.store')),
        updateUrl: @json(isset($barang) ? route('barang.update', $barang) : null),
        editUrlTemplate: @json(route('barang.edit', ['barang' => '__ID__'])),
        terimaBarangPdfUrlTemplate: @json(route('barang.terima-barang.pdf', ['barang' => '__ID__'])),
        shippingLabelPdfUrlTemplate: @json(route('barang.shipping-label.pdf', ['barang' => '__ID__'])),
        productsExportExcelUrlTemplate: @json(route('barang.products.export-excel', ['barang' => '__ID__'])),
        indexUrl: @json(route('barang.index')),
        initialData: @json($barangFormPayload ?? null),
        canExportProducts: @json(auth()->user()->can('export barang')),
        canViewHargaModal: @json($canViewHargaModal ?? false),
        canEditHargaModal: @json($canEditHargaModal ?? false),
        companyItemModalRates: @json($companyItemModalRates ?? []),
        markingPricingsIndex: @json($markingPricingsIndex ?? []),
        markingPricingsUrlTemplate: @json(url('/marking/__ID__/pricings')),
        canEditProductLogistics: true,
        originalPlDownloadUrl: @json(
            isset($barang) && $barang->invoice_file
                ? route('barang.original-pl.download', $barang)
                : null
        ),
        storageMediaBaseUrl: @json(route('storage-media.show')),
        dimensionPhotoUploadUrlTemplate: @json(
            isset($barang)
                ? url('/barang/'.$barang->id.'/products/__PRODUCT__/dimension-photo')
                : null
        ),
        productPhotoUploadUrlTemplate: @json(
            isset($barang)
                ? url('/barang/'.$barang->id.'/products/__PRODUCT__/photo')
                : null
        ),
        stagedProductPhotoUploadUrl: @json(
            isset($barang) ? route('barang.stage-product-photo', $barang) : route('barang.stage-product-photo.create')
        ),
    };
</script>
<script src="{{ asset('Custom_js/Barang/Form.js') }}?v={{ time() }}" defer></script>
@endpush
