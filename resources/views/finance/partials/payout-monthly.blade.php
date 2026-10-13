<div class="glass p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label mb-1">{{ __('sales-salary.filter_period') }}</label>
            <input type="month" id="filterPayoutPeriod" class="form-control" value="{{ now()->format('Y-m') }}">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-glass w-100" id="btnRefreshPayout" style="height:42px">
                <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
            </button>
        </div>
    </div>
    <p class="small mb-0 mt-3" style="color:var(--text-muted)">
        <i class="bi bi-info-circle me-1"></i>{{ __('sales-salary.payout_steps') }}
    </p>
</div>

<div class="glass p-4">
    <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
        <i class="bi bi-bank me-2 text-accent"></i>{{ __('sales-salary.title') }}
        <span class="small fw-normal ms-1" style="color:var(--text-muted)" id="salaryTablePeriodLabel"></span>
    </h6>
    <div class="table-responsive">
        <table id="salesSalaryTable" class="table-glass w-100">
            <thead>
                <tr>
                    <th>{{ __('sales-salary.col_sales') }}</th>
                    <th class="text-center">{{ __('sales-salary.col_items') }}</th>
                    <th>{{ __('sales-salary.col_total') }}</th>
                    <th>{{ __('sales-salary.col_status') }}</th>
                    <th>{{ __('sales-salary.col_proof') }}</th>
                    <th>{{ __('sales-salary.col_uploaded') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
