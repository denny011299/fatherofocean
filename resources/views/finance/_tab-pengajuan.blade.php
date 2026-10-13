<div class="glass p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label mb-1">{{ __('finance.pengajuan_filter_status') }}</label>
            <select id="filterStatus" class="form-select">
                <option value="">{{ __('finance.pengajuan_all_status') }}</option>
                <option value="pending">{{ __('finance.repricing_pending') }}</option>
                <option value="approved">{{ __('finance.pengajuan_status_approved') }}</option>
                <option value="rejected">{{ __('finance.pengajuan_status_rejected') }}</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-glass w-100" id="btnRefreshPengajuan" style="height:42px">
                <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
            </button>
        </div>
    </div>
</div>

<div class="glass p-4">
    <div class="table-responsive">
        <table id="pengajuanTable" class="table-glass w-100">
            <thead>
                <tr>
                    <th>{{ __('barang.label_no_index') }}</th>
                    <th>{{ __('barang.col_marking') }}</th>
                    <th>CBM</th>
                    <th>KG</th>
                    <th>{{ __('finance.pengajuan_col_mode') }}</th>
                    <th>{{ __('finance.pengajuan_col_proposed') }}</th>
                    <th>{{ __('finance.pengajuan_col_status') }}</th>
                    <th>{{ __('finance.pengajuan_col_submitted') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
