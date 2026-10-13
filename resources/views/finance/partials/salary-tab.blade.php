<div class="glass p-3 mb-4">
    <p class="small mb-3" style="color:var(--text-muted)">{{ __('sales-salary.hint') }}</p>
    <div class="row g-2 align-items-end">
        @unless($isSales)
        <div class="col-md-4">
            <label class="form-label mb-1">{{ __('sales-salary.filter_sales') }}</label>
            <select id="filterSalarySales" class="form-select select2" data-placeholder="{{ __('sales-salary.all_sales') }}">
                <option value="">{{ __('sales-salary.all_sales') }}</option>
            </select>
        </div>
        @endunless
        <div class="col-md-2">
            <button type="button" class="btn btn-glass w-100" id="btnRefreshSalary" style="height:42px">
                <i class="bi bi-arrow-clockwise me-1"></i>{{ __('common.refresh') }}
            </button>
        </div>
    </div>
</div>
<div class="glass p-4">
    <h6 class="fw-700 mb-3" style="color:var(--text-primary)">
        <i class="bi bi-cash-stack me-2 text-accent"></i>
        {{ $isSales ? __('sales-salary.tab_salary_history') : __('sales-salary.title') }}
    </h6>
    <div class="table-responsive">
        <table id="salesSalaryTable" class="table-glass w-100">
            <thead>
                <tr>
                    <th>{{ __('sales-salary.col_period') }}</th>
                    @unless($isSales)
                    <th>{{ __('sales-salary.col_sales') }}</th>
                    @endunless
                    <th>{{ __('sales-salary.col_items') }}</th>
                    <th>{{ __('sales-salary.col_total') }}</th>
                    <th>{{ __('sales-salary.col_proof') }}</th>
                    <th>{{ __('sales-salary.col_uploaded') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
