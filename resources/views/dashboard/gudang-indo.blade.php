@extends('layouts.app')
@section('title', __('dashboard.gudang_indo_title'))

@section('content')
<div class="bento-grid mb-4">
    <div class="glass stat-card">
        <div class="stat-label">Container Tiba</div>
        <div class="stat-value text-accent">{{ $arrivedContainers }}</div>
        <div class="stat-change"><i class="bi bi-box-arrow-in-down"></i> Siap bongkar</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">Barang Perlu Kirim</div>
        <div class="stat-value text-warning">{{ $totalToDeliver }}</div>
    </div>
    <div class="glass stat-card">
        <div class="stat-label">Terkirim Hari Ini</div>
        <div class="stat-value text-accent">{{ $deliveredToday }}</div>
    </div>
</div>

<div class="glass p-4">
    <h6 class="fw-700 text-white mb-3">Quick Actions</h6>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('tracking.staff') }}" class="btn btn-accent"><i class="bi bi-signpost-2-fill me-1"></i>Update Tracking</a>
    </div>
</div>
@endsection
