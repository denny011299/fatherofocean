<div class="glass p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label mb-1"><i class="bi bi-search me-1"></i>Cari</label>
            <input type="text" id="filterSearch" class="form-control" placeholder="{{ __('finance.pricing_search_placeholder') }}">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">Start Load Date</label>
            <input type="date" id="filterStartLoadDate" class="form-control">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">End Load Date</label>
            <input type="date" id="filterEndLoadDate" class="form-control">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">Show</label>
            <select id="filterShow" class="form-select select2" data-placeholder="ALL">
                <option value="">ALL</option>
                <option value="priced">Sudah Pricing</option>
                <option value="unpriced">Belum Pricing</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-glass w-100" id="btnResetFilter" style="height:42px">
                <i class="bi bi-x-lg me-1"></i>Reset
            </button>
        </div>
    </div>
</div>

<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-700 text-white mb-0">{{ __('finance.pricing_title') }}</h6>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-glass btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>PRINT (PDF)</button>
        </div>
    </div>

    <div class="table-responsive">
        <table id="pricingTable" class="table-glass w-100">
            <thead>
                <tr>
                    <th>{{ __('finance.col_index_container') }}<br><small class="fw-400">{{ __('barang.col_tanggal_masuk') }} / Sales</small></th>
                    <th>MARK<br><small class="fw-400">Partai</small></th>
                    <th>ITEM(E)</th>
                    <th>CTNS / CBM / Berat</th>
                    <th>Harga</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
