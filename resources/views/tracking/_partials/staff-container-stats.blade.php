<div class="row g-3 mb-4" id="trackingStats">
    <div class="col-6 col-md-3 col-lg">
        <div class="glass stat-card stat-card-premium stat-card-blue">
            <div class="stat-card-header">
                <div class="stat-icon-squircle squircle-blue">
                    <i class="bi bi-archive-fill"></i>
                </div>
                <span class="stat-pill stat-pill-blue">Total</span>
            </div>
            <div>
                <div class="stat-label">{{ __('tracking.stat_total') }}</div>
                <div class="stat-value">{{ $stats['total'] }}</div>
            </div>
        </div>
    </div>
    @foreach(\App\Models\Container::TRACKING_SUMMARY_STATUSES as $status)
    @php
        $cardColor = match($status) {
            'loading' => 'stat-card-amber',
            'etd' => 'stat-card-indigo',
            'eta' => 'stat-card-blue',
            'bea_cukai' => 'stat-card-rose',
            'tiba' => 'stat-card-emerald',
            default => 'stat-card-blue',
        };
        $squircleColor = match($status) {
            'loading' => 'squircle-amber',
            'etd' => 'squircle-indigo',
            'eta' => 'squircle-cyan',
            'bea_cukai' => 'squircle-rose',
            'tiba' => 'squircle-emerald',
            default => 'squircle-royal',
        };
        $pillColor = match($status) {
            'loading', 'bea_cukai' => 'stat-pill-amber',
            'etd' => 'stat-pill-indigo',
            default => 'stat-pill-blue',
        };
    @endphp
    <div class="col-6 col-md-3 col-lg">
        <div class="glass stat-card stat-card-premium {{ $cardColor }}">
            <div class="stat-card-header">
                <div class="stat-icon-squircle {{ $squircleColor }}">
                    <i class="bi bi-{{ $status === 'ditarik_kembali' ? 'arrow-return-left' : ($status === 'loading' ? 'box-seam' : ($status === 'etd' ? 'water' : ($status === 'eta' ? 'pin-map-fill' : ($status === 'bea_cukai' ? 'shield-check' : 'check-circle-fill')))) }}"></i>
                </div>
                <span class="stat-pill {{ $pillColor }}">Status</span>
            </div>
            <div>
                <div class="stat-label">{{ \App\Models\Container::trackingStatusLabel($status) }}</div>
                <div class="stat-value">{{ $stats[$status] ?? 0 }}</div>
            </div>
        </div>
    </div>
    @endforeach
</div>
