@extends('layouts.app')
@section('title', __('dashboard.gudang_indo_title'))

@section('content')
<div class="dashboard-stats-grid mb-4">
    <div class="glass stat-card stat-card-premium stat-card-blue">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-cyan">
                <i class="bi bi-truck-flatbed"></i>
            </div>
            <span class="stat-pill stat-pill-blue">Arrived</span>
        </div>
        <div>
            <div class="stat-label">Container Tiba</div>
            <div class="stat-value text-accent">{{ $arrivedContainers }}</div>
            <div class="stat-change up mt-2"><i class="bi bi-box-arrow-in-down me-1"></i> Siap bongkar</div>
        </div>
    </div>
    <div class="glass stat-card stat-card-premium stat-card-amber">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-amber">
                <i class="bi bi-boxes"></i>
            </div>
            <span class="stat-pill stat-pill-amber">Outbound</span>
        </div>
        <div>
            <div class="stat-label">Barang Perlu Kirim</div>
            <div class="stat-value text-warning">{{ $totalToDeliver }}</div>
            <div class="stat-change mt-2"><i class="bi bi-hourglass-split me-1"></i> Antrian pengiriman</div>
        </div>
    </div>
    <div class="glass stat-card stat-card-premium stat-card-emerald">
        <div class="stat-card-header">
            <div class="stat-icon-squircle squircle-emerald">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <span class="stat-pill stat-pill-blue">Delivered</span>
        </div>
        <div>
            <div class="stat-label">Terkirim Hari Ini</div>
            <div class="stat-value text-accent">{{ $deliveredToday }}</div>
            <div class="stat-change up mt-2"><i class="bi bi-send-check me-1"></i> Selesai terkirim</div>
        </div>
    </div>
</div>

<div class="glass p-4">
    <div class="d-flex align-items-center gap-2 mb-3">
        <div class="stat-icon-squircle squircle-royal" style="width:36px;height:36px;font-size:1rem;border-radius:10px;">
            <i class="bi bi-lightning-charge-fill"></i>
        </div>
        <h6 class="fw-700 mb-0" style="color:var(--text-primary)">Quick Actions</h6>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('tracking.staff') }}" class="btn btn-accent"><i class="bi bi-signpost-2-fill me-1"></i>Update Tracking</a>
    </div>
</div>
@endsection
