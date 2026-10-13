<div class="glass p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label mb-1">{{ __('sales-incentive.filter_period') }}</label>
            <input type="month" id="filterIncentivePeriod" class="form-control" value="{{ $incentivePeriod ?? now()->format('Y-m') }}">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-glass w-100" id="btnRefreshIncentive" style="height:42px">
                <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
            </button>
        </div>
    </div>
</div>

<div class="glass p-4">
    <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
        <i class="bi bi-gift me-2 text-accent"></i>{{ __('sales-incentive.title') }}
        <span class="small fw-normal ms-1" style="color:var(--text-muted)" id="incentiveTablePeriodLabel"></span>
    </h6>
    <div class="table-responsive">
        <table id="salesIncentiveTable" class="table-glass w-100">
            <thead>
                <tr>
                    @unless($isSales)
                    <th>{{ __('sales-incentive.col_sales') }}</th>
                    @endunless
                    <th class="text-center">{{ __('sales-incentive.col_items') }}</th>
                    <th>{{ __('sales-incentive.col_cbm_stuffing') }}</th>
                    <th>{{ __('sales-incentive.col_cbm_paid') }}</th>
                    <th>{{ __('sales-incentive.col_estimasi') }}</th>
                    <th>{{ __('sales-incentive.col_cair') }}</th>
                    <th>{{ __('sales-incentive.col_pending') }}</th>
                    <th>{{ __('sales-incentive.col_status') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
