<style>
.surat-jalan-workspace .sj-actions-cell { min-width: 168px; }
.surat-jalan-workspace .sj-action-group {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.35rem;
}
.surat-jalan-workspace .sj-action-group .btn {
    width: 2.125rem;
    height: 2.125rem;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.surat-jalan-workspace .sj-action-group .btn i { margin: 0 !important; font-size: 1rem; }
.surat-jalan-workspace .marking-badge { max-width: 140px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }
/* Surat Jalan editor (SweetAlert) — semua input wajib bisa diketik */
.swal2-container.sj-editor-open,
.swal2-container.sj-bukti-open {
    pointer-events: auto !important;
    z-index: 2000 !important;
}
.swal2-container.sj-editor-open .swal2-popup,
.swal2-container.sj-editor-open .swal2-html-container,
.swal2-container.sj-bukti-open .swal2-popup,
.swal2-container.sj-bukti-open .swal2-html-container {
    pointer-events: auto !important;
}
.swal2-html-container .sj-bukti-modal input[type="file"],
.swal2-html-container .sj-bukti-modal .sj-bukti-file,
.swal2-html-container .sj-bukti-modal .btn,
.swal2-html-container .sj-bukti-modal a {
    pointer-events: auto !important;
    opacity: 1 !important;
    cursor: pointer !important;
}
.swal2-html-container .sj-editor-form input,
.swal2-html-container .sj-editor-form textarea,
.swal2-html-container .sj-editor-form select,
.swal2-html-container .sj-editor-form .form-control {
    background: var(--input-focus-bg, #fff) !important;
    color: var(--text-primary, #1e293b) !important;
    pointer-events: auto !important;
    opacity: 1 !important;
    -webkit-text-fill-color: var(--text-primary, #1e293b) !important;
    cursor: text !important;
    user-select: text !important;
}
.swal2-html-container .sj-editor-form input:disabled,
.swal2-html-container .sj-editor-form input[readonly],
.swal2-html-container .sj-editor-form textarea:disabled,
.swal2-html-container .sj-editor-form textarea[readonly],
.swal2-html-container .sj-editor-form select:disabled {
    background: var(--input-focus-bg, #fff) !important;
    color: var(--text-primary, #1e293b) !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    cursor: text !important;
}
.swal2-html-container .sj-editor-form input:-webkit-autofill,
.swal2-html-container .sj-editor-form textarea:-webkit-autofill {
    -webkit-box-shadow: 0 0 0 1000px var(--input-focus-bg, #fff) inset !important;
    -webkit-text-fill-color: var(--text-primary, #1e293b) !important;
}
</style>
<div class="surat-jalan-workspace">
    <input type="hidden" class="sj-container-id" value="">
    <input type="hidden" class="sj-trip-id" value="">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge-glass sj-trip-no">Draft</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-glass d-none sj-download-rekapan-sj">
                <i class="bi bi-file-earmark-excel me-1"></i>{{ __('delivery_trip.rekapan_excel_btn') }}
            </a>
            @if(auth()->user()?->hasRole('super_admin'))
            <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-glass d-none sj-download-blank-template"
               title="{{ __('delivery_trip.blank_template_tooltip') }}">
                <i class="bi bi-file-earmark me-1"></i>{{ __('delivery_trip.blank_template_btn') }}
            </a>
            @endif
            <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-glass d-none sj-open-full-page">
                <i class="bi bi-box-arrow-up-right me-1"></i>{{ __('delivery_trip.open_full_page') }}
            </a>
        </div>
    </div>

    <div class="glass-solid p-3 mb-3 sj-markings-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <div>
                <h6 class="fw-700 mb-1">{{ __('delivery_trip.markings_list_title') }}</h6>
                <p class="small text-muted-glass mb-0">{{ __('delivery_trip.markings_per_shipment_hint') }}</p>
            </div>
            <span class="badge-glass sj-markings-count">0</span>
        </div>
        <div class="table-responsive">
            <table class="table-glass table-sm w-100 mb-0">
                <thead>
                    <tr>
                        <th style="width:64px">{{ __('delivery_trip.col_sheet_index') }}</th>
                        <th>{{ __('delivery_trip.col_marking') }}</th>
                        <th>{{ __('delivery_trip.truck_plate') }}</th>
                        <th>{{ __('delivery_trip.driver_name') }}</th>
                        <th>{{ __('tracking.col_ctns') }}</th>
                        <th>{{ __('barang.col_cbm') }}</th>
                        <th>{{ __('delivery_trip.col_status') }}</th>
                        <th class="sj-actions-cell"></th>
                    </tr>
                </thead>
                <tbody class="sj-markings-body">
                    <tr>
                        <td colspan="8" class="text-center text-muted-glass py-3">{{ __('delivery_trip.no_markings_in_container') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
