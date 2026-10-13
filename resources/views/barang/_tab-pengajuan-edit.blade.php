<div class="glass p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label mb-1" for="filterEditPengajuanStatus">{{ __('barang.edit_pengajuan_filter_status') }}</label>
            <select id="filterEditPengajuanStatus" class="form-select">
                <option value="">{{ __('barang.edit_pengajuan_all_status') }}</option>
                <option value="pending">{{ __('barang.approval_pending') }}</option>
                <option value="approved">{{ __('barang.approval_approved') }}</option>
                <option value="rejected">{{ __('barang.approval_rejected') }}</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-glass w-100" id="btnRefreshEditPengajuan" style="height:42px">
                <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
            </button>
        </div>
    </div>
</div>

@if($canApproveEditPengajuan ?? false)
<div id="editPengajuanApproverHint" class="glass p-3 mb-3 d-none" style="border:1px solid rgba(129,140,248,.2)">
    <div class="d-flex gap-2 align-items-start">
        <i class="bi bi-info-circle text-accent mt-1" aria-hidden="true"></i>
        <div class="small" style="color:var(--text-secondary)">
            {{ __('barang.edit_pengajuan_approver_empty_hint') }}
        </div>
    </div>
</div>
@else
<div id="editPengajuanSubmitterEmptyHint" class="glass p-3 mb-3 d-none" style="border:1px solid rgba(129,140,248,.2)">
    <div class="d-flex gap-2 align-items-start">
        <i class="bi bi-send-check text-accent mt-1" aria-hidden="true"></i>
        <div class="small" style="color:var(--text-secondary)">
            {{ __('barang.edit_pengajuan_submitter_empty_hint') }}
        </div>
    </div>
</div>
@endif

<div class="glass p-4">
    <div class="table-responsive">
        <table id="barangEditPengajuanTable" class="table-glass w-100">
            <thead>
                <tr>
                    <th>{{ __('barang.label_no_index') }}</th>
                    <th>{{ __('barang.col_marking') }}</th>
                    <th>CBM</th>
                    <th>KG</th>
                    <th>{{ __('barang.edit_pengajuan_col_changes') }}</th>
                    <th>{{ __('barang.col_status') }}</th>
                    <th>{{ __('barang.edit_pengajuan_col_submitted') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
