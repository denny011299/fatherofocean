<div class="row g-3 mb-4" id="trackingStats">
    <div class="col-6 col-md-3 col-lg">
        <div class="glass stat-card">
            <div class="stat-icon"><i class="bi bi-archive-fill"></i></div>
            <div class="stat-label">{{ __('tracking.stat_total') }}</div>
            <div class="stat-value">{{ $stats['total'] }}</div>
        </div>
    </div>
    @foreach(\App\Models\Container::TRACKING_SUMMARY_STATUSES as $status)
    <div class="col-6 col-md-3 col-lg">
        <div class="glass stat-card">
            <div class="stat-icon"><i class="bi bi-{{ $status === 'ditarik_kembali' ? 'arrow-return-left' : ($status === 'loading' ? 'box-seam' : ($status === 'etd' ? 'water' : ($status === 'eta' ? 'pin-map-fill' : ($status === 'bea_cukai' ? 'shield-check' : 'check-circle-fill')))) }}"></i></div>
            <div class="stat-label">{{ \App\Models\Container::trackingStatusLabel($status) }}</div>
            <div class="stat-value">{{ $stats[$status] ?? 0 }}</div>
        </div>
    </div>
    @endforeach
</div>
