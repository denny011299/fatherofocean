@extends('layouts.app')
@section('title', __('dashboard.gudang_china_title'))

@section('content')
<div class="bento-grid mb-4">
    <div class="glass stat-card">
        <div class="stat-label">Inbound Hari Ini</div>
        <div class="stat-value text-accent">{{ $todayInbound }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">Draft / Pre-manifest</div>
        <div class="stat-value">{{ $draftCount }}</div>
    </div>
    <div class="glass stat-card" style="grid-column: span 2">
        <div class="stat-label">Quick Actions</div>
        <div class="d-flex gap-2 mt-2">
            <a href="{{ route('barang.index') }}" class="btn btn-accent btn-sm"><i class="bi bi-plus-lg me-1"></i>Input Barang</a>
            <a href="{{ route('container.index') }}" class="btn btn-glass btn-sm"><i class="bi bi-truck me-1"></i>Loading Container</a>
        </div>
    </div>
</div>

@if($openContainers->isNotEmpty())
<div class="glass p-4 mb-4">
    <h6 class="fw-700 text-white mb-3"><i class="bi bi-truck me-2"></i>Container Open</h6>
    <div class="row g-3">
        @foreach($openContainers as $c)
        <div class="col-md-6 col-lg-4">
            <div class="glass-solid p-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-700 text-white">{{ $c->partai }}</div>
                        <div class="text-muted-glass small">{{ $c->type }} | {{ $c->container_no ?? 'N/A' }}</div>
                    </div>
                    <span class="badge-glass badge-status-inbound">Open</span>
                </div>
                <div class="mt-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted-glass">Kapasitas</span>
                        <span class="text-white fw-600">{{ $c->capacity_percent }}%</span>
                    </div>
                    <div class="capacity-bar">
                        <div class="fill {{ $c->capacity_level }}" style="width: {{ min($c->capacity_percent, 100) }}%"></div>
                    </div>
                    <div class="text-muted-glass mt-1" style="font-size:.72rem">
                        {{ number_format($c->used_volume, 2) }} / {{ number_format($c->max_volume, 2) }} CBM
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

<div class="glass p-4">
    <h6 class="fw-700 text-white mb-3">Barang Terbaru</h6>
    <div class="table-responsive">
        <table class="table-glass">
            <thead>
                <tr><th>{{ __('dashboard.col_no_index') }}</th><th>Marking</th><th>CBM</th><th>GW</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach($recentBarangs as $b)
                <tr>
                    <td class="fw-600 text-white">{{ $b->no_index }}</td>
                    <td>{{ $b->marking ?? '-' }}</td>
                    <td>{{ \App\Support\CbmFormat::format((float) $b->total_cbm) }}</td>
                    <td>{{ $b->total_gross_weight }} kg</td>
                    <td><span class="badge-glass {{ $b->status_badge }}">{{ ucfirst($b->status) }}</span></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
