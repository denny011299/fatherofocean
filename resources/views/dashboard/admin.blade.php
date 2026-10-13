@extends('layouts.app')
@section('title', $isSales ? __('dashboard.title_sales') : __('dashboard.title'))

@section('content')
@php
    $widgets = $widgets ?? [];
    $hasAnyWidget = collect($widgets)->contains(true);
@endphp

@if($isSales && ($widgets['master_marking'] ?? false))
<div class="glass p-3 mb-4 d-flex align-items-center gap-2" style="border-left: 3px solid var(--accent);">
    <i class="bi bi-funnel-fill text-accent"></i>
    <span style="font-size:.85rem;color:var(--body-color)">
        @if($isSupervisor ?? false)
            {{ __('dashboard.supervisor_team_filter', ['count' => $totalMasterMarking]) }}
        @else
            {{ __('dashboard.sales_filter', ['count' => $totalMasterMarking]) }}
        @endif
    </span>
</div>
@endif

@if(!$hasAnyWidget)
<div class="glass p-5 text-center text-muted-glass">
    <i class="bi bi-grid" style="font-size:2rem;opacity:.4"></i>
    <p class="mt-3 mb-0">{{ __('dashboard.no_widgets') }}</p>
</div>
@else

@php
    $linkKiriman = auth()->user()->can('view tracking')
        ? route('tracking.staff', ['status' => \App\Models\Barang::STATUS_ETD])
        : (auth()->user()->can('view barang') ? route('barang.index') : null);
    $linkMarking = auth()->user()->can('view marking') ? route('marking.index') : null;
    $linkTagihan = auth()->user()->can('view invoice') ? route('finance.index', ['unpaid' => 1]) : null;
    $linkOmset = auth()->user()->can('export omset')
        ? route('export.omset')
        : (auth()->user()->can('view invoice') ? route('finance.index', ['status' => 'paid']) : null);
    $linkTeamOmsetTotal = ($widgets['sales_revenue'] ?? false) && ($isSales ?? false) ? $linkOmset : null;
    $linkCompanyRevenue = ($widgets['company_value'] ?? false) && ($isSuperAdmin ?? false) && auth()->user()->can('view sales revenue')
        ? route('finance.company-revenue.index')
        : null;
    $totalCompanyValueDisplay = $companyRevenue
        ? (float) (($companyRevenue['estimasi_company'] ?? 0) + ($companyRevenue['fix_company'] ?? 0))
        : 0;
@endphp

{{-- Row 1: Stat Cards --}}
@if(($widgets['active_shipments'] ?? false) || ($widgets['master_marking'] ?? false) || ($widgets['invoices'] ?? false) || ($widgets['sales_revenue'] ?? false) || ($widgets['company_value'] ?? false) || ($widgets['omset'] ?? false) || ($widgets['total_cbm'] ?? false))
<div class="bento-grid mb-4">
    @if($widgets['active_shipments'] ?? false)
    @if($linkKiriman)
    <a href="{{ $linkKiriman }}" class="glass stat-card stat-card-link">
    @else
    <div class="glass stat-card">
    @endif
        <i class="bi bi-send stat-icon"></i>
        <div class="stat-label">{{ __('dashboard.active_shipments') }}</div>
        <div class="stat-value">{{ number_format($totalKirimanBerjalan) }}</div>
        <div class="stat-change up"><i class="bi bi-truck"></i> {{ __('dashboard.on_the_way') }}</div>
    @if($linkKiriman)</a>@else</div>@endif
    @endif

    @if($widgets['master_marking'] ?? false)
    @if($linkMarking)
    <a href="{{ $linkMarking }}" class="glass stat-card stat-card-link">
    @else
    <div class="glass stat-card">
    @endif
        <i class="bi bi-bookmark-star stat-icon"></i>
        <div class="stat-label">{{ __('dashboard.master_marking') }}</div>
        <div class="stat-value">{{ number_format($totalMasterMarking) }}</div>
        <div class="stat-change"><i class="bi bi-tags"></i> {{ __('dashboard.total_marking') }}</div>
    @if($linkMarking)</a>@else</div>@endif
    @endif

    @if($widgets['invoices'] ?? false)
    @if($linkTagihan)
    <a href="{{ $linkTagihan }}" class="glass stat-card stat-card-link">
    @else
    <div class="glass stat-card">
    @endif
        <i class="bi bi-receipt stat-icon"></i>
        <div class="stat-label">{{ __('dashboard.total_invoices') }}</div>
        <div class="stat-value text-warning">{{ number_format($totalTagihan) }}</div>
        <div class="stat-change down"><i class="bi bi-exclamation-circle"></i> {{ __('dashboard.unpaid') }}</div>
    @if($linkTagihan)</a>@else</div>@endif
    @endif

    @if(($widgets['sales_revenue'] ?? false) && ($isSales ?? false) && !empty($teamOmsetTotal))
    <div class="glass stat-card" id="teamOmsetTotalCard">
        <i class="bi bi-cash-stack stat-icon"></i>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
            <div class="stat-label mb-0">{{ ($isSupervisor ?? false) ? __('dashboard.total_team_omset') : __('dashboard.total_my_omset') }}</div>
            <div class="d-flex gap-1 align-items-center team-metrics-filter">
                <select id="teamOmsetTotalYear" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:5rem" aria-label="{{ __('dashboard.all_years') }}">
                    <option value="">{{ __('dashboard.all_years') }}</option>
                    @for($y = now()->year; $y >= 1999; $y--)
                        <option value="{{ $y }}" @selected((int) ($teamOmsetTotal['year'] ?? 0) === $y)>{{ $y }}</option>
                    @endfor
                </select>
                <select id="teamOmsetTotalMonth" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:5.5rem" aria-label="{{ __('dashboard.all_months') }}">
                    <option value="">{{ __('dashboard.all_months') }}</option>
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" @selected((int) ($teamOmsetTotal['month'] ?? 0) === $m)>{{ \Carbon\Carbon::create(null, $m, 1)->translatedFormat('M') }}</option>
                    @endfor
                </select>
            </div>
        </div>
        <div class="team-metrics-body position-relative">
            <div class="team-metrics-spinner d-none" id="teamOmsetTotalSpinner" aria-hidden="true">
                <div class="spinner-border spinner-border-sm text-accent" role="status"></div>
            </div>
            @if($linkTeamOmsetTotal)
            <a href="{{ $linkTeamOmsetTotal }}" class="text-decoration-none d-block">
                <div class="stat-value text-accent" id="teamOmsetTotalAmount">Rp {{ number_format($teamOmsetTotal['omset'] ?? 0, 0, ',', '.') }}</div>
            </a>
            @else
            <div class="stat-value text-accent" id="teamOmsetTotalAmount">Rp {{ number_format($teamOmsetTotal['omset'] ?? 0, 0, ',', '.') }}</div>
            @endif
            <div class="stat-change up" id="teamOmsetTotalLastMonth">
                <i class="bi bi-graph-up-arrow"></i>
                {{ __('dashboard.total_my_omset_last_month', [
                    'month' => $teamOmsetTotal['last_month_label'] ?? '',
                    'amount' => number_format($teamOmsetTotal['omset_last_month'] ?? 0, 0, ',', '.'),
                ]) }}
            </div>
            <div class="text-muted-glass mt-1" style="font-size:.72rem;line-height:1.35">
                <i class="bi bi-info-circle"></i>
                {{ ($isSupervisor ?? false) ? __('dashboard.total_team_omset_help') : __('dashboard.total_my_omset_help') }}
            </div>
        </div>
    </div>
    @elseif(($widgets['company_value'] ?? false) && ($isSuperAdmin ?? false))
    <div class="glass stat-card" id="companyValueCard">
        <i class="bi bi-building stat-icon"></i>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
            <div class="stat-label mb-0">{{ __('dashboard.total_company_value') }}</div>
            <div class="d-flex gap-1 align-items-center flex-wrap" id="companyValueFilter">
                <select id="companyValueSales" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:8.5rem" aria-label="{{ __('dashboard.filter_all_sales') }}">
                    <option value="">{{ __('dashboard.filter_all_sales') }}</option>
                    @foreach($dashboardSalesOptions ?? [] as $sales)
                        <option value="{{ $sales->id }}" @selected((int) ($companyFilterSalesId ?? 0) === (int) $sales->id)>{{ $sales->name }}</option>
                    @endforeach
                </select>
                <select id="companyValueYear" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:5rem" aria-label="{{ __('dashboard.all_years') }}">
                    <option value="">{{ __('dashboard.all_years') }}</option>
                    @for($y = now()->year; $y >= 1999; $y--)
                        <option value="{{ $y }}" @selected((int) ($companyFilterYear ?? 0) === $y)>{{ $y }}</option>
                    @endfor
                </select>
                <select id="companyValueMonth" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:5.5rem" aria-label="{{ __('dashboard.all_months') }}">
                    <option value="">{{ __('dashboard.all_months') }}</option>
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" @selected((int) ($companyFilterMonth ?? 0) === $m)>{{ \Carbon\Carbon::create(null, $m, 1)->translatedFormat('M') }}</option>
                    @endfor
                </select>
            </div>
        </div>
        <div class="company-value-body position-relative" id="companyValueBody">
            <div class="company-value-spinner d-none" id="companyValueSpinner" aria-hidden="true">
                <div class="spinner-border spinner-border-sm text-accent" role="status"></div>
            </div>
            @if($linkCompanyRevenue)
            <a href="{{ $linkCompanyRevenue }}" class="text-decoration-none d-block">
                <div class="stat-value text-accent" id="companyValueAmount">Rp {{ number_format($totalCompanyValueDisplay, 0, ',', '.') }}</div>
            </a>
            @else
            <div class="stat-value text-accent" id="companyValueAmount">Rp {{ number_format($totalCompanyValueDisplay, 0, ',', '.') }}</div>
            @endif
            <div class="stat-change up" id="companyValueLastMonth">
                <i class="bi bi-graph-up-arrow"></i>
                {{ __('dashboard.company_value_last_month', [
                    'month' => $companyRevenueLastMonthLabel ?? '',
                    'amount' => number_format($companyRevenueLastMonth ?? 0, 0, ',', '.'),
                ]) }}
            </div>
        </div>
    </div>
    @elseif(($widgets['omset'] ?? false) && $linkOmset && !($isSales ?? false))
    <a href="{{ $linkOmset }}" class="glass stat-card stat-card-link">
        <i class="bi bi-cash-stack stat-icon"></i>
        <div class="stat-label">{{ __('dashboard.total_revenue') }}</div>
        <div class="stat-value text-accent">Rp {{ number_format($totalOmset, 0, ',', '.') }}</div>
        <div class="stat-change up"><i class="bi bi-graph-up-arrow"></i> {{ __('dashboard.paid_in_full') }}</div>
    </a>
    @elseif(($widgets['omset'] ?? false) && !($isSales ?? false))
    <div class="glass stat-card">
        <i class="bi bi-cash-stack stat-icon"></i>
        <div class="stat-label">{{ __('dashboard.total_revenue') }}</div>
        <div class="stat-value text-accent">Rp {{ number_format($totalOmset, 0, ',', '.') }}</div>
        <div class="stat-change up"><i class="bi bi-graph-up-arrow"></i> {{ __('dashboard.paid_in_full') }}</div>
    </div>
    @endif

    @if(($isSales ?? false) && ($widgets['omset'] ?? false) && !empty($teamMetrics))
    <div class="glass stat-card" id="teamOmsetCard">
        <i class="bi bi-cash-stack stat-icon"></i>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
            <div class="stat-label mb-0">{{ ($isSupervisor ?? false) ? __('dashboard.team_omset_team') : __('dashboard.team_omset') }}</div>
            <div class="d-flex gap-1 align-items-center team-metrics-filter">
                <select id="teamMetricsYear" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:5rem" aria-label="{{ __('dashboard.all_years') }}">
                    <option value="">{{ __('dashboard.all_years') }}</option>
                    @for($y = now()->year; $y >= 1999; $y--)
                        <option value="{{ $y }}" @selected((int) ($teamMetrics['year'] ?? 0) === $y)>{{ $y }}</option>
                    @endfor
                </select>
                <select id="teamMetricsMonth" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:5.5rem" aria-label="{{ __('dashboard.all_months') }}">
                    <option value="">{{ __('dashboard.all_months') }}</option>
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" @selected((int) ($teamMetrics['month'] ?? 0) === $m)>{{ \Carbon\Carbon::create(null, $m, 1)->translatedFormat('M') }}</option>
                    @endfor
                </select>
            </div>
        </div>
        <div class="team-metrics-body position-relative">
            <div class="team-metrics-spinner d-none" id="teamMetricsSpinner" aria-hidden="true">
                <div class="spinner-border spinner-border-sm text-accent" role="status"></div>
            </div>
            <div class="stat-value text-accent" id="teamOmsetAmount">Rp {{ number_format($teamMetrics['commission'] ?? 0, 0, ',', '.') }}</div>
            <div class="stat-change up" id="teamOmsetLastMonth">
                <i class="bi bi-graph-up-arrow"></i>
                {{ __('dashboard.team_commission_last_month', [
                    'month' => $teamMetrics['last_month_label'] ?? '',
                    'amount' => number_format($teamMetrics['commission_last_month'] ?? 0, 0, ',', '.'),
                ]) }}
            </div>
            <div class="text-muted-glass mt-1" style="font-size:.72rem;line-height:1.35">
                <i class="bi bi-info-circle"></i>
                @if($isSupervisor ?? false)
                    {{ __('dashboard.team_commission_help_supervisor') }}
                @else
                    {{ __('dashboard.team_commission_help_sales', ['rate' => number_format((float) ($salesCommissionRate ?? 2.5), 2, ',', '.')]) }}
                @endif
            </div>
        </div>
    </div>
    @endif

    @if(!($isSales ?? false) && !empty($adminCbmMetrics))
    <div class="glass stat-card" id="adminCbmCard">
        <i class="bi bi-boxes stat-icon"></i>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
            <div class="stat-label mb-0">{{ __('dashboard.admin_total_cbm') }}</div>
            <div class="d-flex gap-1 align-items-center flex-wrap" id="adminCbmFilter">
                <select id="adminCbmSales" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:8.5rem" aria-label="{{ __('dashboard.filter_all_sales') }}">
                    <option value="">{{ __('dashboard.filter_all_sales') }}</option>
                    @foreach($dashboardSalesOptions ?? [] as $sales)
                        <option value="{{ $sales->id }}" @selected((int) ($adminCbmMetrics['sales_id'] ?? 0) === (int) $sales->id)>{{ $sales->name }}</option>
                    @endforeach
                </select>
                <select id="adminCbmYear" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:5rem" aria-label="{{ __('dashboard.all_years') }}">
                    <option value="">{{ __('dashboard.all_years') }}</option>
                    @for($y = now()->year; $y >= 1999; $y--)
                        <option value="{{ $y }}" @selected((int) ($adminCbmMetrics['year'] ?? 0) === $y)>{{ $y }}</option>
                    @endfor
                </select>
                <select id="adminCbmMonth" class="form-select form-select-sm" style="width:auto;font-size:.7rem;max-width:5.5rem" aria-label="{{ __('dashboard.all_months') }}">
                    <option value="">{{ __('dashboard.all_months') }}</option>
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" @selected((int) ($adminCbmMetrics['month'] ?? 0) === $m)>{{ \Carbon\Carbon::create(null, $m, 1)->translatedFormat('M') }}</option>
                    @endfor
                </select>
            </div>
        </div>
        <div class="admin-cbm-body position-relative" id="adminCbmBody">
            <div class="admin-cbm-spinner d-none" id="adminCbmSpinner" aria-hidden="true">
                <div class="spinner-border spinner-border-sm text-accent" role="status"></div>
            </div>
            <div class="stat-value text-accent" id="adminCbmAmount">{{ \App\Support\CbmFormat::format($adminCbmMetrics['cbm'] ?? 0) }} CBM</div>
            <div class="stat-change up" id="adminCbmLastMonth">
                <i class="bi bi-graph-up-arrow"></i>
                {{ __('dashboard.team_cbm_last_month', [
                    'month' => $adminCbmMetrics['last_month_label'] ?? '',
                    'amount' => \App\Support\CbmFormat::format($adminCbmMetrics['cbm_last_month'] ?? 0),
                ]) }}
            </div>
            <div class="text-muted-glass mt-1" style="font-size:.72rem;line-height:1.35">
                <i class="bi bi-info-circle"></i> {{ __('dashboard.admin_cbm_help') }}
            </div>
        </div>
    </div>
    @endif

    @if(($isSales ?? false) && ($widgets['total_cbm'] ?? false) && !empty($teamMetrics))
    <div class="glass stat-card" id="teamCbmCard">
        <i class="bi bi-boxes stat-icon"></i>
        <div class="stat-label">{{ __('dashboard.team_total_cbm') }}</div>
        <div class="stat-value text-accent" id="teamCbmAmount">{{ \App\Support\CbmFormat::format($teamMetrics['cbm'] ?? 0) }} CBM</div>
        <div class="stat-change up" id="teamCbmLastMonth">
            <i class="bi bi-graph-up-arrow"></i>
            {{ __('dashboard.team_cbm_last_month', [
                'month' => $teamMetrics['last_month_label'] ?? '',
                'amount' => \App\Support\CbmFormat::format($teamMetrics['cbm_last_month'] ?? 0),
            ]) }}
        </div>
        <div class="text-muted-glass mt-1" style="font-size:.72rem;line-height:1.35">
            <i class="bi bi-info-circle"></i> {{ __('dashboard.team_cbm_help') }}
        </div>
    </div>
    @endif
</div>
@endif

{{-- Row 2: Chart + Kritik Saran --}}
@if(($widgets['shipment_chart'] ?? false) || ($widgets['feedback'] ?? false))
<div class="row g-3 mb-4">
    @if($widgets['shipment_chart'] ?? false)
    <div class="col-lg-{{ ($widgets['feedback'] ?? false) ? '8' : '12' }}">
        <div class="glass p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h6 class="fw-700 mb-0" style="color: var(--text-primary)">{{ __('dashboard.shipment_analysis') }}</h6>
                    <small class="text-muted-glass">{{ __('dashboard.shipment_traffic_year') }}</small>
                </div>
                <select id="shipmentChartYear" class="form-select form-select-sm" style="width:auto;font-size:.78rem;max-width:6rem">
                    @foreach($shipmentYears ?? [now()->year] as $yr)
                        <option value="{{ $yr }}" @selected(($shipmentChartYear ?? now()->year) === $yr)>{{ $yr }}</option>
                    @endforeach
                </select>
            </div>
            <div id="shipmentChart"></div>
        </div>
    </div>
    @endif
    @if($widgets['feedback'] ?? false)
    <div class="col-lg-{{ ($widgets['shipment_chart'] ?? false) ? '4' : '12' }}">
        <div class="glass p-4 h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h6 class="fw-700 mb-0" style="color: var(--text-primary)">Kritik & Saran</h6>
                    <small class="text-muted-glass">{{ $kritikSaran->count() }} feedback terbaru</small>
                </div>
                <span class="badge-glass" style="background: rgba(var(--accent-rgb),.12); color: var(--accent);">
                    <i class="bi bi-chat-dots"></i> {{ $kritikSaran->count() }}
                </span>
            </div>
            <div class="kritik-saran-list flex-grow-1" style="overflow-y: auto; max-height: 340px;">
                @forelse($kritikSaran as $ks)
                <div class="kritik-saran-item glass-solid p-3 mb-2" style="border-radius: var(--radius-xl);" id="ks-{{ $ks->id }}">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:28px;height:28px;background:rgba(var(--accent-rgb),.15);">
                                <i class="bi bi-person-fill" style="font-size:.75rem;color:var(--accent)"></i>
                            </div>
                            <div>
                                <div class="fw-600" style="font-size:.8rem;color:var(--text-primary)">{{ $ks->user?->name ?? 'Anonim' }}</div>
                                <div class="text-muted-glass" style="font-size:.65rem">{{ $ks->created_at->diffForHumans() }}</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            @for($i = 1; $i <= 5; $i++)
                                <i class="bi bi-star{{ $i <= $ks->score ? '-fill' : '' }}" style="font-size:.6rem;color:{{ $i <= $ks->score ? '#fbbf24' : 'var(--text-dim)' }}"></i>
                            @endfor
                        </div>
                    </div>
                    <p class="mb-2" style="font-size:.8rem;color:var(--body-color);line-height:1.4">{{ Str::limit($ks->review, 120) }}</p>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge-glass" style="font-size:.65rem;background:rgba(var(--accent-rgb),.08)">
                            <i class="bi bi-box-seam"></i> {{ $ks->barang?->marking ?? $ks->barang?->no_index ?? '-' }}
                        </span>
                        @if($ks->assignee)
                            <span class="badge-glass" style="font-size:.65rem;background:rgba(52,211,153,.1);color:#34d399">
                                <i class="bi bi-person-check"></i> {{ $ks->assignee->name }}
                            </span>
                        @elseif(auth()->user()->can('assign feedback'))
                            <button type="button" class="badge-glass btn-assign-ks border-0" style="font-size:.65rem;background:rgba(168,85,247,.1);color:#a855f7;cursor:pointer" data-id="{{ $ks->id }}">
                                <i class="bi bi-person-gear"></i> Assign Staff
                            </button>
                        @else
                            <span class="badge-glass" style="font-size:.65rem;background:rgba(234,179,8,.1);color:#fbbf24">
                                <i class="bi bi-hourglass-split"></i> Belum di-assign
                            </span>
                        @endif
                    </div>
                </div>
                @empty
                <div class="text-center py-5 text-muted-glass">
                    <i class="bi bi-chat-square-text" style="font-size:2rem;opacity:.4"></i>
                    <p class="mt-2 mb-0" style="font-size:.85rem">Belum ada kritik & saran</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
    @endif
</div>
@endif

{{-- Row 3: Daily Task --}}
@if($widgets['daily_task'] ?? false)
@php
    $dailyTaskBarangUrl = static function ($barang): ?string {
        $user = auth()->user();
        if ($user?->can('edit barang')) {
            return route('barang.edit', $barang);
        }
        if ($user?->can('view barang')) {
            return route('barang.show', $barang);
        }

        return null;
    };
@endphp
<div class="glass p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h6 class="fw-700 mb-0" style="color: var(--text-primary)">Daily Task</h6>
            <small class="text-muted-glass">Barang masuk hari ini &mdash; {{ now()->translatedFormat('d F Y') }}</small>
        </div>
        <div class="d-flex gap-2">
            @can('manage task')
            <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                <i class="bi bi-plus-lg me-1"></i> Tambah Task
            </button>
            @endcan
            <button type="button" class="btn btn-glass btn-sm" data-bs-toggle="modal" data-bs-target="#allTaskModal">
                Lihat Semua
            </button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table-glass">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Marking</th>
                    <th>Tgl Masuk Barang</th>
                    <th>No Resi</th>
                    <th>Coly</th>
                    <th>Task</th>
                    <th style="width:90px">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($todayBarangs as $idx => $b)
                @php $barangTaskUrl = $dailyTaskBarangUrl($b); @endphp
                <tr>
                    <td class="fw-600" style="color:var(--text-primary)">{{ $idx + 1 }}</td>
                    <td>
                        @if($barangTaskUrl)
                        <a href="{{ $barangTaskUrl }}" class="fw-600 text-decoration-none" style="color:var(--accent)">{{ $b->marking ?? '-' }}</a>
                        @else
                        <span class="fw-600" style="color:var(--accent)">{{ $b->marking ?? '-' }}</span>
                        @endif
                    </td>
                    <td class="text-muted-glass">{{ $b->tanggal_masuk ? $b->tanggal_masuk->format('d M Y') : '-' }}</td>
                    <td class="fw-600" style="color:var(--text-primary)">{{ $b->no_index ?? '-' }}</td>
                    <td>{{ $b->ctns ?? '-' }}</td>
                    <td>
                        <div class="task-list" id="task-list-{{ $b->id }}"
                            @if(!$b->hasCompleteProductList()) data-products-incomplete="1" @endif
                            @if($barangTaskUrl) data-barang-url="{{ $barangTaskUrl }}" @endif>
                            @forelse($b->tasks as $task)
                            <div class="task-item {{ $task->is_done ? 'done' : '' }}" id="task-{{ $task->id }}">
                                @can('manage task')
                                <i class="bi {{ $task->is_done ? 'bi-check-circle-fill' : 'bi-circle' }} task-toggle" data-id="{{ $task->id }}" style="cursor:pointer"></i>
                                @else
                                <i class="bi {{ $task->is_done ? 'bi-check-circle-fill' : 'bi-circle' }}" style="opacity:.5"></i>
                                @endcan
                                <span>{{ $task->title }}</span>
                                @if($task->assignee)
                                <span class="badge-glass" style="font-size:.6rem;padding:.15rem .4rem;background:rgba(var(--accent-rgb),.1);color:var(--accent);margin-left:4px">{{ $task->assignee->name }}</span>
                                @endif
                                @can('manage task')
                                <i class="bi bi-x task-delete" data-id="{{ $task->id }}" style="cursor:pointer;font-size:.7rem;margin-left:auto" title="Hapus"></i>
                                @endcan
                            </div>
                            @empty
                            @if(!$b->hasCompleteProductList())
                            @if($barangTaskUrl)
                            <a href="{{ $barangTaskUrl }}" class="task-item task-virtual incomplete-products-msg task-open-barang text-decoration-none" style="font-size:.78rem;cursor:pointer" title="{{ __('dashboard.task_complete_products') }}">
                                <i class="bi bi-exclamation-circle" style="color:var(--warning);opacity:.85"></i>
                                <span>{{ __('dashboard.task_incomplete_products') }}</span>
                            </a>
                            @else
                            <div class="task-item task-virtual incomplete-products-msg" style="font-size:.78rem">
                                <i class="bi bi-exclamation-circle" style="color:var(--warning);opacity:.85"></i>
                                <span>{{ __('dashboard.task_incomplete_products') }}</span>
                            </div>
                            @endif
                            @else
                            <div class="text-muted-glass no-task-msg" style="font-size:.78rem;font-style:italic">{{ __('dashboard.task_no_tasks') }}</div>
                            @endif
                            @endforelse
                        </div>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            @if($barangTaskUrl)
                            <a href="{{ $barangTaskUrl }}"
                                class="btn btn-sm btn-glass d-inline-flex align-items-center justify-content-center"
                                style="width:32px;height:32px;padding:0;border-radius:50%;color:var(--accent)"
                                title="{{ __('dashboard.open_barang') }}">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                            @endif
                            @can('manage task')
                            <button type="button"
                                class="btn btn-sm btn-glass d-inline-flex align-items-center justify-content-center btn-kelola-task"
                                style="width:32px;height:32px;padding:0;border-radius:50%;color:var(--accent)"
                                title="Kelola Task"
                                data-barang-id="{{ $b->id }}"
                                data-marking="{{ $b->marking ?? $b->no_index ?? '-' }}">
                                <i class="bi bi-pencil"></i>
                            </button>
                            @endcan
                            @php
                                $nl = "\n";
                                $allTasks = $b->tasks->map(function($t) {
                                    $icon = $t->is_done ? json_decode('"\u2705"') : json_decode('"\u274C"');
                                    $name = $t->assignee ? " ({$t->assignee->name})" : '';
                                    return $icon . ' ' . $t->title . $name;
                                })->implode("\n");

                                $waText = "*Today Task - {$b->no_index}*" . $nl
                                    . "Marking: {$b->marking}" . $nl
                                    . "Tgl Masuk: " . ($b->tanggal_masuk ? $b->tanggal_masuk->format('d/m/Y') : '-') . $nl
                                    . "Coly: " . ($b->ctns ?? '-') . $nl . $nl
                                    . ($allTasks ?: ($b->hasCompleteProductList()
                                        ? __('dashboard.task_no_tasks')
                                        : __('dashboard.task_incomplete_products')));

                                $phone = $b->customer?->phone ?? '';
                                $phone = preg_replace('/[^0-9]/', '', $phone);
                                if (str_starts_with($phone, '0')) $phone = '62' . substr($phone, 1);
                            @endphp
                            <a href="https://wa.me/{{ $phone }}?text={{ rawurlencode($waText) }}"
                               target="_blank"
                               class="btn btn-sm btn-glass d-inline-flex align-items-center justify-content-center"
                               style="width:32px;height:32px;padding:0;border-radius:50%;color:#25d366"
                               title="Kirim via WhatsApp">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted-glass py-4">Belum ada barang masuk hari ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- All Task Modal --}}
<div class="modal fade" id="allTaskModal" tabindex="-1" aria-labelledby="allTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-700" id="allTaskModalLabel" style="font-size:.9rem">
                    <i class="bi bi-list-task me-2"></i>Semua Task
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive" style="max-height:70vh;overflow:auto;">
                    <table class="table-glass mb-0">
                        <thead>
                            <tr>
                                <th style="width:60px">#</th>
                                <th>Marking</th>
                                <th>No Resi</th>
                                <th>Tgl Masuk Barang</th>
                                <th>Coly</th>
                                <th style="width:110px">Progress</th>
                                <th>Daftar Task</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $allTaskRowNo = 1; @endphp
                            @forelse($taskBarangOptions as $barangTask)
                                @php
                                    $barangTasks = $barangTask->tasks ?? collect();
                                    $totalTaskCount = $barangTasks->count();
                                    $doneTaskCount = $barangTasks->where('is_done', true)->count();
                                    $modalBarangUrl = $dailyTaskBarangUrl($barangTask);
                                @endphp
                                <tr>
                                    <td class="fw-600" style="color:var(--text-primary)">{{ $allTaskRowNo++ }}</td>
                                    <td>
                                        @if($modalBarangUrl)
                                        <a href="{{ $modalBarangUrl }}" class="fw-600 text-decoration-none" style="color:var(--accent)">{{ $barangTask->marking ?? '-' }}</a>
                                        @else
                                        <span class="fw-600" style="color:var(--accent)">{{ $barangTask->marking ?? '-' }}</span>
                                        @endif
                                    </td>
                                    <td class="fw-600" style="color:var(--text-primary)">{{ $barangTask->no_index ?? '-' }}</td>
                                    <td class="text-muted-glass">{{ $barangTask->tanggal_masuk ? $barangTask->tanggal_masuk->format('d M Y') : '-' }}</td>
                                    <td>{{ $barangTask->ctns ?? '-' }}</td>
                                    <td>
                                        @if($totalTaskCount > 0)
                                            <span class="badge-glass" style="font-size:.68rem">
                                                {{ $doneTaskCount }}/{{ $totalTaskCount }}
                                            </span>
                                        @else
                                            <span class="text-muted-glass" style="font-size:.78rem">0/0</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($totalTaskCount > 0)
                                            <div class="d-flex flex-column gap-1">
                                                @foreach($barangTasks as $task)
                                                    <div class="d-flex align-items-center gap-2" style="font-size:.78rem;">
                                                        <i class="bi {{ $task->is_done ? 'bi-check-circle-fill' : 'bi-circle' }}" style="color:{{ $task->is_done ? 'var(--success, #34d399)' : 'var(--text-muted)' }}"></i>
                                                        <span class="{{ $task->is_done ? 'text-decoration-line-through text-muted-glass' : '' }}">{{ $task->title }}</span>
                                                        @if($task->assignee)
                                                            <span class="badge-glass" style="font-size:.6rem;padding:.15rem .4rem;background:rgba(var(--accent-rgb),.1);color:var(--accent)">
                                                                {{ $task->assignee->name }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @elseif(!$barangTask->hasCompleteProductList())
                                            @if($modalBarangUrl)
                                            <a href="{{ $modalBarangUrl }}" class="d-flex align-items-center gap-2 text-decoration-none task-open-barang" style="font-size:.78rem;cursor:pointer" title="{{ __('dashboard.task_complete_products') }}">
                                                <i class="bi bi-exclamation-circle" style="color:var(--warning);opacity:.85"></i>
                                                <span style="color:var(--text-primary)">{{ __('dashboard.task_incomplete_products') }}</span>
                                            </a>
                                            @else
                                            <div class="d-flex align-items-center gap-2 text-muted-glass" style="font-size:.78rem">
                                                <i class="bi bi-exclamation-circle" style="color:var(--warning);opacity:.85"></i>
                                                <span>{{ __('dashboard.task_incomplete_products') }}</span>
                                            </div>
                                            @endif
                                        @else
                                            <span class="text-muted-glass" style="font-size:.78rem">{{ __('dashboard.task_no_tasks') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted-glass py-4">Belum ada data task.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

{{-- Add Task Modal --}}
@can('manage task')
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-700" style="font-size:.9rem"><i class="bi bi-list-check me-2"></i>Tambah Task</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Pilih Barang / Marking</label>
                    <select class="form-select select2" id="taskBarangSelect" data-placeholder="-- Pilih Marking --">
                        <option value="">-- Pilih Marking --</option>
                        @foreach($taskBarangOptions as $b)
                            <option value="{{ $b->id }}">
                                {{ $b->marking ?? '-' }} &mdash; {{ $b->no_index }} ({{ $b->ctns }} coly)@if($b->tanggal_masuk && !$b->tanggal_masuk->isToday()) &middot; {{ $b->tanggal_masuk->format('d M Y') }}@endif
                            </option>
                        @endforeach
                    </select>
                    @if($taskBarangOptions->isEmpty())
                    <small class="text-muted-glass d-block mt-1" style="font-size:.75rem">Tidak ada barang aktif. Input barang baru di menu Barang.</small>
                    @endif
                </div>
                <div class="mb-3">
                    <label class="form-label">Assign ke Staff</label>
                    <select class="form-select" id="taskStaffSelect">
                        <option value="">-- Tidak di-assign --</option>
                        @foreach($staffList as $staff)
                            <option value="{{ $staff->id }}">{{ $staff->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">List Task</label>
                    <div id="taskInputList">
                        <div class="d-flex gap-2 mb-2 task-input-row">
                            <span class="d-flex align-items-center text-muted-glass" style="font-size:.75rem;width:20px">1.</span>
                            <input type="text" class="form-control form-control-sm" placeholder="Nama task..." style="font-size:.82rem">
                            <button type="button" class="btn btn-sm text-muted-glass btn-remove-row" style="padding:0 .4rem;font-size:.9rem;opacity:.4" title="Hapus">&times;</button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-glass btn-sm mt-1" id="btnAddTaskRow">
                        <i class="bi bi-plus me-1"></i> Tambah baris
                    </button>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-accent btn-sm" id="btnSubmitTasks">
                    <i class="bi bi-check2-all me-1"></i> Simpan Tasks
                </button>
            </div>
        </div>
    </div>
</div>
@endcan

{{-- Kelola Task Modal --}}
@can('manage task')
<div class="modal fade" id="kelolaTaskModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-700" style="font-size:.9rem">
                    <i class="bi bi-list-check me-2"></i>Kelola Task - <span id="kelolaTaskMarking"></span>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height:400px;overflow-y:auto">
                <input type="hidden" id="kelolaBarangId">
                <div id="kelolaTaskList">
                    <div class="text-center py-4 text-muted-glass" id="kelolaTaskEmpty">
                        <i class="bi bi-clipboard-check" style="font-size:1.5rem;opacity:.4"></i>
                        <p class="mt-2 mb-0" style="font-size:.82rem">Belum ada task</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <div class="d-flex gap-2 w-100">
                    <input type="text" class="form-control form-control-sm" id="kelolaNewTaskInput" placeholder="Tambah task baru..." style="font-size:.82rem">
                    <button type="button" class="btn btn-accent btn-sm flex-shrink-0" id="btnKelolaAddTask">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endcan
@endif {{-- daily task widget --}}

{{-- Assign Modal (admin only) --}}
@if($widgets['feedback'] ?? false)
@can('assign feedback')
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-700" style="font-size:.9rem">Assign ke Staff</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="assignRatingId">
                <div class="mb-3">
                    <label class="form-label">Pilih Staff</label>
                    <select class="form-select" id="assignStaffSelect">
                        <option value="">-- Pilih Staff --</option>
                        @foreach($staffList as $staff)
                            <option value="{{ $staff->id }}">{{ $staff->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Catatan (Opsional)</label>
                    <textarea class="form-control" id="assignNotes" rows="2" placeholder="Catatan untuk evaluasi..."></textarea>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-accent btn-sm" id="btnSubmitAssign">
                    <i class="bi bi-person-check me-1"></i> Assign
                </button>
            </div>
        </div>
    </div>
</div>
@endcan
@endif

@endif {{-- hasAnyWidget --}}
@endsection

@if($hasAnyWidget ?? false)
@if($widgets['shipment_chart'] ?? false)
@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3/dist/apexcharts.css">
@endpush
@endif

@push('scripts')
@if($widgets['shipment_chart'] ?? false)
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3"></script>
@endif
<script>
@if($widgets['shipment_chart'] ?? false)
const monthLabels = @json($monthlyShipments->keys()->map(fn($m) => \Carbon\Carbon::createFromFormat('Y-m', $m)->translatedFormat('M Y'))->values());
const monthData = @json($monthlyShipments->values());

const isDark = document.body.getAttribute('data-theme') !== 'light';
const accentColor = isDark ? '#F0A030' : '#E88824';
const textColor = isDark ? '#8892a4' : '#64748b';
const gridColor = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';

const options = {
    series: [{
        name: 'Total Pengiriman',
        data: monthData
    }],
    chart: {
        type: 'area',
        height: 320,
        fontFamily: 'Inter, sans-serif',
        toolbar: { show: false },
        background: 'transparent',
        animations: {
            enabled: true,
            easing: 'easeinout',
            speed: 800,
        }
    },
    colors: [accentColor],
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.45,
            opacityTo: 0.05,
            stops: [0, 90, 100]
        }
    },
    stroke: {
        curve: 'smooth',
        width: 3,
    },
    dataLabels: {
        enabled: true,
        background: {
            enabled: true,
            borderRadius: 4,
            borderColor: 'transparent',
            foreColor: isDark ? '#fff' : '#0f172a',
            dropShadow: { enabled: false },
            padding: 4,
        },
        style: {
            fontSize: '11px',
            fontWeight: 700,
            colors: [accentColor],
        },
        offsetY: -8,
    },
    markers: {
        size: 5,
        colors: [accentColor],
        strokeColors: isDark ? '#0C1E38' : '#ffffff',
        strokeWidth: 3,
        hover: { sizeOffset: 3 }
    },
    xaxis: {
        categories: monthLabels,
        labels: {
            style: { colors: textColor, fontSize: '11px' },
            rotate: -45,
            rotateAlways: false,
        },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        labels: {
            style: { colors: textColor, fontSize: '11px' },
            formatter: (val) => Math.round(val)
        }
    },
    grid: {
        borderColor: gridColor,
        strokeDashArray: 4,
        xaxis: { lines: { show: false } },
    },
    tooltip: {
        theme: isDark ? 'dark' : 'light',
        y: { formatter: (val) => val + ' kiriman' }
    },
};

const shipmentChartEl = document.querySelector('#shipmentChart');
let chart = null;
if (shipmentChartEl) {
    chart = new ApexCharts(shipmentChartEl, options);
    chart.render();
}
@endif

@if(($widgets['company_value'] ?? false) && ($isSuperAdmin ?? false))
(function () {
    const card = document.getElementById('companyValueCard');
    const yearSelect = document.getElementById('companyValueYear');
    const monthSelect = document.getElementById('companyValueMonth');
    const salesSelect = document.getElementById('companyValueSales');
    const amountEl = document.getElementById('companyValueAmount');
    const lastMonthEl = document.getElementById('companyValueLastMonth');
    if (!card || !yearSelect || !monthSelect || !amountEl || !lastMonthEl) {
        return;
    }

    const companyValueUrl = @json(route('dashboard.company-value'));
    const companyValueLoadFailed = @json(__('dashboard.company_value_load_failed'));

    function setCompanyValueLoading(loading) {
        card.classList.toggle('is-loading', loading);
        yearSelect.disabled = loading;
        monthSelect.disabled = loading;
        if (salesSelect) {
            salesSelect.disabled = loading;
        }
        document.getElementById('companyValueSpinner')?.classList.toggle('d-none', !loading);
    }

    function syncCompanyValueUrlParams() {
        const params = new URLSearchParams(window.location.search);
        const year = yearSelect.value;
        const month = monthSelect.value;
        const salesId = salesSelect?.value || '';
        if (year) {
            params.set('company_year', year);
        } else {
            params.delete('company_year');
        }
        if (month) {
            params.set('company_month', month);
        } else {
            params.delete('company_month');
        }
        if (salesId) {
            params.set('sales_id', salesId);
        } else {
            params.delete('sales_id');
        }
        const qs = params.toString();
        history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
    }

    function fetchCompanyValue() {
        setCompanyValueLoading(true);
        const url = new URL(companyValueUrl, window.location.origin);
        const year = yearSelect.value;
        const month = monthSelect.value;
        const salesId = salesSelect?.value || '';
        if (year) {
            url.searchParams.set('company_year', year);
        }
        if (month) {
            url.searchParams.set('company_month', month);
        }
        if (salesId) {
            url.searchParams.set('sales_id', salesId);
        }

        axios.get(url.toString())
            .then((res) => {
                amountEl.textContent = 'Rp ' + res.data.total_value_formatted;
                lastMonthEl.innerHTML = '<i class="bi bi-graph-up-arrow"></i> ' + res.data.last_month_text;
                syncCompanyValueUrlParams();
            })
            .catch(() => showToast(companyValueLoadFailed, 'danger'))
            .finally(() => setCompanyValueLoading(false));
    }

    yearSelect.addEventListener('change', fetchCompanyValue);
    monthSelect.addEventListener('change', fetchCompanyValue);
    salesSelect?.addEventListener('change', fetchCompanyValue);
})();
@endif

@if(!($isSales ?? false) && !empty($adminCbmMetrics))
(function () {
    const card = document.getElementById('adminCbmCard');
    const yearSelect = document.getElementById('adminCbmYear');
    const monthSelect = document.getElementById('adminCbmMonth');
    const salesSelect = document.getElementById('adminCbmSales');
    const amountEl = document.getElementById('adminCbmAmount');
    const lastMonthEl = document.getElementById('adminCbmLastMonth');
    if (!card || !yearSelect || !monthSelect || !amountEl || !lastMonthEl) {
        return;
    }

    const adminCbmUrl = @json(route('dashboard.admin-cbm'));
    const loadFailed = @json(__('dashboard.admin_cbm_load_failed'));

    function setLoading(loading) {
        card.classList.toggle('is-loading', loading);
        yearSelect.disabled = loading;
        monthSelect.disabled = loading;
        if (salesSelect) {
            salesSelect.disabled = loading;
        }
        document.getElementById('adminCbmSpinner')?.classList.toggle('d-none', !loading);
    }

    function fetchAdminCbm() {
        setLoading(true);
        const url = new URL(adminCbmUrl, window.location.origin);
        if (yearSelect.value) {
            url.searchParams.set('company_year', yearSelect.value);
        }
        if (monthSelect.value) {
            url.searchParams.set('company_month', monthSelect.value);
        }
        if (salesSelect?.value) {
            url.searchParams.set('sales_id', salesSelect.value);
        }

        axios.get(url.toString())
            .then((res) => {
                amountEl.textContent = res.data.cbm_formatted + ' CBM';
                lastMonthEl.innerHTML = '<i class="bi bi-graph-up-arrow"></i> ' + res.data.cbm_last_month_text;
            })
            .catch(() => showToast(loadFailed, 'danger'))
            .finally(() => setLoading(false));
    }

    yearSelect.addEventListener('change', fetchAdminCbm);
    monthSelect.addEventListener('change', fetchAdminCbm);
    salesSelect?.addEventListener('change', fetchAdminCbm);
})();
@endif

@if(($isSales ?? false) && !empty($teamOmsetTotal) && ($widgets['sales_revenue'] ?? false))
(function () {
    const yearSelect = document.getElementById('teamOmsetTotalYear');
    const monthSelect = document.getElementById('teamOmsetTotalMonth');
    const card = document.getElementById('teamOmsetTotalCard');
    const amountEl = document.getElementById('teamOmsetTotalAmount');
    const lastEl = document.getElementById('teamOmsetTotalLastMonth');
    if (!yearSelect || !monthSelect) {
        return;
    }

    const url = @json(route('dashboard.team-omset-total'));
    const loadFailed = @json(__('dashboard.team_omset_total_load_failed'));

    function setLoading(loading) {
        card?.classList.toggle('is-loading', loading);
        yearSelect.disabled = loading;
        monthSelect.disabled = loading;
        document.getElementById('teamOmsetTotalSpinner')?.classList.toggle('d-none', !loading);
    }

    function fetchTeamOmsetTotal() {
        setLoading(true);
        const reqUrl = new URL(url, window.location.origin);
        if (yearSelect.value) {
            reqUrl.searchParams.set('company_year', yearSelect.value);
        }
        if (monthSelect.value) {
            reqUrl.searchParams.set('company_month', monthSelect.value);
        }

        axios.get(reqUrl.toString())
            .then((res) => {
                if (amountEl && res.data.omset_formatted) {
                    amountEl.textContent = 'Rp ' + res.data.omset_formatted;
                }
                if (lastEl && res.data.omset_last_month_text) {
                    lastEl.innerHTML = '<i class="bi bi-graph-up-arrow"></i> ' + res.data.omset_last_month_text;
                }
            })
            .catch(() => showToast(loadFailed, 'danger'))
            .finally(() => setLoading(false));
    }

    yearSelect.addEventListener('change', fetchTeamOmsetTotal);
    monthSelect.addEventListener('change', fetchTeamOmsetTotal);
})();
@endif

@if(($isSales ?? false) && !empty($teamMetrics) && (($widgets['omset'] ?? false) || ($widgets['total_cbm'] ?? false)))
(function () {
    const yearSelect = document.getElementById('teamMetricsYear');
    const monthSelect = document.getElementById('teamMetricsMonth');
    const omsetCard = document.getElementById('teamOmsetCard');
    const cbmCard = document.getElementById('teamCbmCard');
    const omsetAmountEl = document.getElementById('teamOmsetAmount');
    const omsetLastEl = document.getElementById('teamOmsetLastMonth');
    const cbmAmountEl = document.getElementById('teamCbmAmount');
    const cbmLastEl = document.getElementById('teamCbmLastMonth');
    if (!yearSelect || !monthSelect) {
        return;
    }

    const teamMetricsUrl = @json(route('dashboard.team-metrics'));
    const loadFailed = @json(__('dashboard.team_metrics_load_failed'));

    function setLoading(loading) {
        [omsetCard, cbmCard].forEach((el) => el?.classList.toggle('is-loading', loading));
        yearSelect.disabled = loading;
        monthSelect.disabled = loading;
        document.getElementById('teamMetricsSpinner')?.classList.toggle('d-none', !loading);
    }

    function fetchTeamMetrics() {
        setLoading(true);
        const url = new URL(teamMetricsUrl, window.location.origin);
        if (yearSelect.value) {
            url.searchParams.set('company_year', yearSelect.value);
        }
        if (monthSelect.value) {
            url.searchParams.set('company_month', monthSelect.value);
        }

        axios.get(url.toString())
            .then((res) => {
                if (omsetAmountEl && res.data.commission_formatted) {
                    omsetAmountEl.textContent = 'Rp ' + res.data.commission_formatted;
                }
                if (omsetLastEl && res.data.commission_last_month_text) {
                    omsetLastEl.innerHTML = '<i class="bi bi-graph-up-arrow"></i> ' + res.data.commission_last_month_text;
                }
                if (cbmAmountEl && res.data.cbm_formatted) {
                    cbmAmountEl.textContent = res.data.cbm_formatted + ' CBM';
                }
                if (cbmLastEl && res.data.cbm_last_month_text) {
                    cbmLastEl.innerHTML = '<i class="bi bi-graph-up-arrow"></i> ' + res.data.cbm_last_month_text;
                }
            })
            .catch(() => showToast(loadFailed, 'danger'))
            .finally(() => setLoading(false));
    }

    yearSelect.addEventListener('change', fetchTeamMetrics);
    monthSelect.addEventListener('change', fetchTeamMetrics);
})();
@endif

@if($widgets['shipment_chart'] ?? false)
document.getElementById('shipmentChartYear')?.addEventListener('change', function () {
    if (!chart) return;
    const year = this.value;
    const url = new URL(@json(route('dashboard.shipment-chart')), window.location.origin);
    url.searchParams.set('year', year);
    axios.get(url.toString()).then(res => {
        chart.updateOptions({
            xaxis: { categories: res.data.labels },
            series: [{ name: 'Total Pengiriman', data: res.data.data }],
        });
        const params = new URLSearchParams(window.location.search);
        params.set('chart_year', year);
        history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }).catch(() => showToast('Gagal memuat data chart', 'danger'));
});

document.getElementById('themeToggle')?.addEventListener('click', () => {
    if (!chart) return;
    setTimeout(() => {
        const nowDark = document.body.getAttribute('data-theme') !== 'light';
        const newAccent = nowDark ? '#F0A030' : '#E88824';
        const newText = nowDark ? '#8892a4' : '#64748b';
        const newGrid = nowDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';

        chart.updateOptions({
            colors: [newAccent],
            xaxis: { labels: { style: { colors: newText } } },
            yaxis: { labels: { style: { colors: newText } } },
            grid: { borderColor: newGrid },
            tooltip: { theme: nowDark ? 'dark' : 'light' },
            markers: {
                colors: [newAccent],
                strokeColors: nowDark ? '#0C1E38' : '#ffffff',
            },
            dataLabels: {
                background: { foreColor: nowDark ? '#fff' : '#0f172a' },
                style: { colors: [newAccent] },
            },
        });
    }, 100);
});
@endif

@if($widgets['daily_task'] ?? false)
const taskNoTasksLabel = @json(__('dashboard.task_no_tasks'));
const taskIncompleteProductsLabel = @json(__('dashboard.task_incomplete_products'));

function maybeRestoreVirtualProductTask(barangId) {
    const list = document.getElementById('task-list-' + barangId);
    if (!list || list.dataset.productsIncomplete !== '1') {
        return;
    }
    if (list.querySelectorAll('.task-item:not(.task-virtual)').length > 0) {
        return;
    }
    if (list.querySelector('.incomplete-products-msg')) {
        return;
    }
    list.querySelector('.no-task-msg')?.remove();
    const url = list.dataset.barangUrl || '';
    const div = document.createElement(url ? 'a' : 'div');
    div.className = 'task-item task-virtual incomplete-products-msg' + (url ? ' task-open-barang text-decoration-none' : '');
    div.style.fontSize = '.78rem';
    if (url) {
        div.href = url;
        div.title = @json(__('dashboard.task_complete_products'));
        div.style.cursor = 'pointer';
    }
    div.innerHTML = '<i class="bi bi-exclamation-circle" style="color:var(--warning);opacity:.85"></i>'
        + '<span>' + escapeHtml(taskIncompleteProductsLabel) + '</span>';
    list.appendChild(div);
}

function clearVirtualProductTask(list) {
    list.querySelectorAll('.incomplete-products-msg, .no-task-msg').forEach(el => el.remove());
}

// Task toggle & delete
function bindTaskEvents() {
    document.querySelectorAll('.task-toggle').forEach(el => {
        el.onclick = function() {
            const id = this.dataset.id;
            const item = document.getElementById('task-' + id);
            axios.patch('/barang-task/' + id + '/toggle').then(res => {
                if (res.data.is_done) {
                    item.classList.add('done');
                    this.classList.replace('bi-circle', 'bi-check-circle-fill');
                } else {
                    item.classList.remove('done');
                    this.classList.replace('bi-check-circle-fill', 'bi-circle');
                }
            });
        };
    });

    document.querySelectorAll('.task-delete').forEach(el => {
        el.onclick = function() {
            const id = this.dataset.id;
            const item = document.getElementById('task-' + id);
            axios.delete('/barang-task/' + id).then(() => {
                const barangId = item.closest('.task-list')?.id?.replace('task-list-', '');
                item.remove();
                if (barangId) {
                    maybeRestoreVirtualProductTask(barangId);
                }
            });
        };
    });
}
bindTaskEvents();

@can('manage task')
// Kelola Task Modal
const kelolaModalEl = document.getElementById('kelolaTaskModal');
const kelolaModal = kelolaModalEl ? new bootstrap.Modal(kelolaModalEl) : null;

function renderKelolaTask(task) {
    const div = document.createElement('div');
    div.className = 'kelola-task-row d-flex align-items-center gap-2 py-2 px-1';
    div.style.cssText = 'border-bottom:1px solid rgba(255,255,255,.06);';
    div.id = 'kelola-task-' + task.id;
    div.dataset.id = task.id;

    const isDone = task.is_done;
    div.innerHTML =
        '<i class="bi ' + (isDone ? 'bi-check-circle-fill' : 'bi-circle') + ' kelola-toggle" style="cursor:pointer;font-size:1rem;color:' + (isDone ? 'var(--success, #34d399)' : 'var(--text-muted)') + ';flex-shrink:0" title="Toggle status"></i>' +
        '<span class="kelola-task-title flex-grow-1' + (isDone ? ' text-decoration-line-through' : '') + '" style="font-size:.85rem;color:var(--text-primary)">' + escapeHtml(task.title) + '</span>' +
        (task.assignee_name ? '<span class="badge-glass" style="font-size:.6rem;padding:.15rem .4rem;background:rgba(var(--accent-rgb),.1);color:var(--accent);flex-shrink:0">' + escapeHtml(task.assignee_name) + '</span>' : '') +
        '<i class="bi bi-pencil kelola-edit" style="cursor:pointer;font-size:.75rem;color:var(--text-muted);flex-shrink:0" title="Edit nama task"></i>' +
        '<i class="bi bi-trash kelola-delete" style="cursor:pointer;font-size:.75rem;color:#fb7185;flex-shrink:0" title="Hapus task"></i>';

    bindKelolaRowEvents(div, task.id);
    return div;
}

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function bindKelolaRowEvents(row, taskId) {
    row.querySelector('.kelola-toggle').addEventListener('click', function() {
        const icon = this;
        const titleEl = row.querySelector('.kelola-task-title');
        axios.patch('/barang-task/' + taskId + '/toggle').then(res => {
            if (res.data.is_done) {
                icon.classList.replace('bi-circle', 'bi-check-circle-fill');
                icon.style.color = 'var(--success, #34d399)';
                titleEl.classList.add('text-decoration-line-through');
            } else {
                icon.classList.replace('bi-check-circle-fill', 'bi-circle');
                icon.style.color = 'var(--text-muted)';
                titleEl.classList.remove('text-decoration-line-through');
            }
            syncDashboardTask(taskId, res.data.is_done);
        });
    });

    row.querySelector('.kelola-edit').addEventListener('click', function() {
        const titleEl = row.querySelector('.kelola-task-title');
        const oldTitle = titleEl.textContent;
        const input = document.createElement('input');
        input.type = 'text';
        input.value = oldTitle;
        input.className = 'form-control form-control-sm';
        input.style.cssText = 'font-size:.82rem;padding:.2rem .5rem';
        titleEl.replaceWith(input);
        input.focus();
        input.select();

        function save() {
            const newTitle = input.value.trim();
            if (!newTitle || newTitle === oldTitle) {
                const span = document.createElement('span');
                span.className = 'kelola-task-title flex-grow-1';
                span.style.cssText = 'font-size:.85rem;color:var(--text-primary)';
                span.textContent = oldTitle;
                input.replaceWith(span);
                return;
            }
            axios.patch('/barang-task/' + taskId + '/update', { title: newTitle }).then(res => {
                const span = document.createElement('span');
                span.className = 'kelola-task-title flex-grow-1';
                span.style.cssText = 'font-size:.85rem;color:var(--text-primary)';
                span.textContent = res.data.title;
                input.replaceWith(span);
                updateDashboardTaskTitle(taskId, res.data.title);
            }).catch(() => {
                const span = document.createElement('span');
                span.className = 'kelola-task-title flex-grow-1';
                span.style.cssText = 'font-size:.85rem;color:var(--text-primary)';
                span.textContent = oldTitle;
                input.replaceWith(span);
                showToast('Gagal mengubah task', 'danger');
            });
        }

        input.addEventListener('blur', save);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); save(); }
            if (e.key === 'Escape') { input.value = oldTitle; save(); }
        });
    });

    row.querySelector('.kelola-delete').addEventListener('click', function() {
        Swal.fire({
            title: 'Hapus task ini?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Hapus',
            cancelButtonText: 'Batal',
            customClass: { popup: 'glass' },
        }).then(result => {
            if (!result.isConfirmed) return;
            axios.delete('/barang-task/' + taskId).then(() => {
                row.remove();
                removeDashboardTask(taskId);
                const list = document.getElementById('kelolaTaskList');
                if (!list.querySelector('.kelola-task-row')) {
                    document.getElementById('kelolaTaskEmpty').style.display = '';
                }
            });
        });
    });
}

function syncDashboardTask(taskId, isDone) {
    const item = document.getElementById('task-' + taskId);
    if (!item) return;
    const icon = item.querySelector('.task-toggle, .bi-check-circle-fill, .bi-circle');
    if (!icon) return;
    if (isDone) {
        item.classList.add('done');
        icon.classList.replace('bi-circle', 'bi-check-circle-fill');
    } else {
        item.classList.remove('done');
        icon.classList.replace('bi-check-circle-fill', 'bi-circle');
    }
}

function updateDashboardTaskTitle(taskId, newTitle) {
    const item = document.getElementById('task-' + taskId);
    if (!item) return;
    const span = item.querySelector('span');
    if (span) span.textContent = newTitle;
}

function removeDashboardTask(taskId) {
    const item = document.getElementById('task-' + taskId);
    if (!item) {
        return;
    }
    const barangId = item.closest('.task-list')?.id?.replace('task-list-', '');
    item.remove();
    if (barangId) {
        maybeRestoreVirtualProductTask(barangId);
    }
}

function openKelolaModal(barangId, marking) {
    document.getElementById('kelolaBarangId').value = barangId;
    document.getElementById('kelolaTaskMarking').textContent = marking;
    document.getElementById('kelolaNewTaskInput').value = '';

    const list = document.getElementById('kelolaTaskList');
    const emptyEl = document.getElementById('kelolaTaskEmpty');

    list.querySelectorAll('.kelola-task-row').forEach(r => r.remove());
    emptyEl.style.display = '';

    const taskListEl = document.getElementById('task-list-' + barangId);
    if (taskListEl) {
        const taskItems = taskListEl.querySelectorAll('.task-item:not(.task-virtual)');
        if (taskItems.length > 0) {
            emptyEl.style.display = 'none';
            taskItems.forEach(item => {
                const id = item.id.replace('task-', '');
                const isDone = item.classList.contains('done');
                const titleSpan = item.querySelector('span');
                const title = titleSpan ? titleSpan.textContent : '';
                const assigneeBadge = item.querySelector('.badge-glass');
                const assigneeName = assigneeBadge ? assigneeBadge.textContent.trim() : '';
                const row = renderKelolaTask({ id, is_done: isDone, title, assignee_name: assigneeName });
                list.appendChild(row);
            });
        }
    }

    if (kelolaModal) {
        kelolaModal.show();
    }
}

document.querySelectorAll('.btn-kelola-task').forEach(btn => {
    btn.addEventListener('click', function() {
        openKelolaModal(this.dataset.barangId, this.dataset.marking);
    });
});

document.getElementById('btnKelolaAddTask').addEventListener('click', function() {
    addKelolaTask();
});

document.getElementById('kelolaNewTaskInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); addKelolaTask(); }
});

function addKelolaTask() {
    const input = document.getElementById('kelolaNewTaskInput');
    const title = input.value.trim();
    if (!title) return;

    const barangId = document.getElementById('kelolaBarangId').value;
    const btn = document.getElementById('btnKelolaAddTask');
    btn.disabled = true;

    axios.post('/barang-task', {
        barang_id: barangId,
        tasks: [title],
    }).then(res => {
        input.value = '';
        const list = document.getElementById('kelolaTaskList');
        document.getElementById('kelolaTaskEmpty').style.display = 'none';

        res.data.tasks.forEach(task => {
            const row = renderKelolaTask({ id: task.id, is_done: false, title: task.title, assignee_name: res.data.assignee_name || '' });
            list.appendChild(row);

            const dashList = document.getElementById('task-list-' + barangId);
            if (dashList) {
                clearVirtualProductTask(dashList);
                const div = document.createElement('div');
                div.className = 'task-item';
                div.id = 'task-' + task.id;
                div.innerHTML = '<i class="bi bi-circle task-toggle" data-id="' + task.id + '" style="cursor:pointer"></i>'
                    + '<span>' + escapeHtml(task.title) + '</span>'
                    + '<i class="bi bi-x task-delete" data-id="' + task.id + '" style="cursor:pointer;font-size:.7rem;margin-left:auto" title="Hapus"></i>';
                dashList.appendChild(div);
            }
        });
        bindTaskEvents();
    }).catch(() => {
        showToast('Gagal menambah task', 'danger');
    }).finally(() => {
        btn.disabled = false;
        input.focus();
    });
}

// Add Task Modal
const addTaskModalEl = document.getElementById('addTaskModal');
const addTaskModal = addTaskModalEl ? new bootstrap.Modal(addTaskModalEl) : null;

function renumberRows() {
    document.querySelectorAll('#taskInputList .task-input-row').forEach((row, i) => {
        row.querySelector('span').textContent = (i + 1) + '.';
    });
}

document.getElementById('btnAddTaskRow').addEventListener('click', function() {
    const list = document.getElementById('taskInputList');
    const count = list.querySelectorAll('.task-input-row').length + 1;
    const row = document.createElement('div');
    row.className = 'd-flex gap-2 mb-2 task-input-row';
    row.innerHTML = '<span class="d-flex align-items-center text-muted-glass" style="font-size:.75rem;width:20px">' + count + '.</span>'
        + '<input type="text" class="form-control form-control-sm" placeholder="Nama task..." style="font-size:.82rem">'
        + '<button type="button" class="btn btn-sm text-muted-glass btn-remove-row" style="padding:0 .4rem;font-size:.9rem;opacity:.4" title="Hapus">&times;</button>';
    list.appendChild(row);
    row.querySelector('input').focus();
    row.querySelector('.btn-remove-row').addEventListener('click', function() {
        row.remove();
        renumberRows();
    });
});

document.querySelectorAll('.btn-remove-row').forEach(btn => {
    btn.addEventListener('click', function() {
        if (document.querySelectorAll('.task-input-row').length > 1) {
            this.closest('.task-input-row').remove();
            renumberRows();
        }
    });
});

document.getElementById('btnSubmitTasks').addEventListener('click', function() {
    const barangId = document.getElementById('taskBarangSelect').value;
    const staffId = document.getElementById('taskStaffSelect').value;
    const inputs = document.querySelectorAll('#taskInputList .task-input-row input');
    const tasks = [];
    inputs.forEach(inp => { if (inp.value.trim()) tasks.push(inp.value.trim()); });

    if (!barangId) { showToast('Pilih barang/marking dulu', 'warning'); return; }
    if (tasks.length === 0) { showToast('Isi minimal 1 task', 'warning'); return; }

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    axios.post('/barang-task', {
        barang_id: barangId,
        assigned_to: staffId || null,
        tasks: tasks,
    }).then(res => {
        const list = document.getElementById('task-list-' + barangId);
        if (!list) { location.reload(); return; }

        clearVirtualProductTask(list);

        res.data.tasks.forEach(task => {
            const div = document.createElement('div');
            div.className = 'task-item';
            div.id = 'task-' + task.id;
            let html = '<i class="bi bi-circle task-toggle" data-id="' + task.id + '" style="cursor:pointer"></i>'
                + '<span>' + task.title + '</span>';
            if (res.data.assignee_name) {
                html += '<span class="badge-glass" style="font-size:.6rem;padding:.15rem .4rem;background:rgba(var(--accent-rgb),.1);color:var(--accent);margin-left:4px">' + res.data.assignee_name + '</span>';
            }
            html += '<i class="bi bi-x task-delete" data-id="' + task.id + '" style="cursor:pointer;font-size:.7rem;margin-left:auto" title="Hapus"></i>';
            div.innerHTML = html;
            list.appendChild(div);
        });

        bindTaskEvents();
        if (addTaskModal) {
            addTaskModal.hide();
        }
        showToast(res.data.tasks.length + ' task berhasil ditambahkan', 'success');

        // Reset modal
        $('#taskBarangSelect').val('').trigger('change');
        document.getElementById('taskStaffSelect').value = '';
        document.getElementById('taskInputList').innerHTML = '<div class="d-flex gap-2 mb-2 task-input-row"><span class="d-flex align-items-center text-muted-glass" style="font-size:.75rem;width:20px">1.</span><input type="text" class="form-control form-control-sm" placeholder="Nama task..." style="font-size:.82rem"><button type="button" class="btn btn-sm text-muted-glass btn-remove-row" style="padding:0 .4rem;font-size:.9rem;opacity:.4" title="Hapus">&times;</button></div>';
    }).catch(() => {
        showToast('Gagal menyimpan task', 'danger');
    }).finally(() => {
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-check2-all me-1"></i> Simpan Tasks';
    });
});
@endcan

@endif {{-- daily task widget --}}

@if($widgets['feedback'] ?? false)
@can('assign feedback')
// Assign feedback logic
const assignModalEl = document.getElementById('assignModal');
const assignModal = assignModalEl ? new bootstrap.Modal(assignModalEl) : null;

document.querySelectorAll('.btn-assign-ks').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('assignRatingId').value = this.dataset.id;
        document.getElementById('assignStaffSelect').value = '';
        document.getElementById('assignNotes').value = '';
        assignModal?.show();
    });
});

document.getElementById('btnSubmitAssign').addEventListener('click', function() {
    const ratingId = document.getElementById('assignRatingId').value;
    const staffId = document.getElementById('assignStaffSelect').value;
    const notes = document.getElementById('assignNotes').value;

    if (!staffId) {
        showToast('Pilih staff terlebih dahulu', 'warning');
        return;
    }

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    axios.post('/feedback/' + ratingId + '/assign', {
        assigned_to: staffId,
        admin_notes: notes,
    }).then(res => {
        assignModal?.hide();
        const item = document.getElementById('ks-' + ratingId);
        const btn = item.querySelector('.btn-assign-ks');
        if (btn) {
            const badge = document.createElement('span');
            badge.className = 'badge-glass';
            badge.style.cssText = 'font-size:.65rem;background:rgba(52,211,153,.1);color:#34d399';
            badge.innerHTML = '<i class="bi bi-person-check"></i> ' + res.data.assignee_name;
            btn.replaceWith(badge);
        }
        showToast('Feedback berhasil di-assign', 'success');
    }).catch(() => {
        showToast('Gagal assign feedback', 'danger');
    }).finally(() => {
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-person-check me-1"></i> Assign';
    });
});
@endcan
@endif

</script>
@endpush
@endif


