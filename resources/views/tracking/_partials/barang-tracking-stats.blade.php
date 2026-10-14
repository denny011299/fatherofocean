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
    @foreach(\App\Models\Barang::SHIPMENT_STATUSES as $status)
    @php
        $cardColor = match($status) {
            'stuffing' => 'stat-card-amber',
            'etd' => 'stat-card-indigo',
            'eta' => 'stat-card-blue',
            'ditarik_kembali' => 'stat-card-rose',
            'selesai', 'delivered' => 'stat-card-emerald',
            default => 'stat-card-blue',
        };
        $squircleColor = match($status) {
            'stuffing' => 'squircle-amber',
            'etd' => 'squircle-indigo',
            'eta' => 'squircle-cyan',
            'ditarik_kembali' => 'squircle-rose',
            'selesai', 'delivered' => 'squircle-emerald',
            default => 'squircle-royal',
        };
        $pillColor = match($status) {
            'stuffing', 'ditarik_kembali' => 'stat-pill-amber',
            'etd' => 'stat-pill-indigo',
            default => 'stat-pill-blue',
        };
    @endphp
    <div class="col-6 col-md-3 col-lg">
        <div class="glass stat-card stat-card-premium {{ $cardColor }}">
            <div class="stat-card-header">
                <div class="stat-icon-squircle {{ $squircleColor }}">
                    <i class="bi bi-{{ $status === 'ditarik_kembali' ? 'arrow-return-left' : ($status === 'stuffing' ? 'box-seam' : ($status === 'etd' ? 'water' : ($status === 'eta' ? 'pin-map-fill' : 'check-circle-fill'))) }}"></i>
                </div>
                <span class="stat-pill {{ $pillColor }}">Status</span>
            </div>
            <div>
                <div class="stat-label">{{ __('barang.status_' . $status) }}</div>
                <div class="stat-value">{{ $stats[$status] }}</div>
            </div>
        </div>
    </div>
    @endforeach
</div>
