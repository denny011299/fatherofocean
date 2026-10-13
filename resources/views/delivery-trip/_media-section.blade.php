<div class="glass p-4 mb-3">
    <h6 class="fw-700 mb-2">{{ $title }}</h6>
    @if($photos->isEmpty() && $videos->isEmpty())
        <p class="small text-muted-glass mb-0">{{ __('delivery_trip.media_empty') }}</p>
    @else
        @if($photos->isNotEmpty())
        <div class="mb-2 small text-muted-glass">{{ __('delivery_trip.photos') }}</div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach($photos as $m)
            <a href="{{ $m['url'] }}" target="_blank" rel="noopener" class="d-block">
                <img src="{{ $m['url'] }}" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:8px;border:1px solid var(--glass-border)">
            </a>
            @endforeach
        </div>
        @endif
        @if($videos->isNotEmpty())
        <div class="mb-2 small text-muted-glass">{{ __('delivery_trip.videos') }}</div>
        <div class="d-flex flex-column gap-2">
            @foreach($videos as $m)
            <div>
                <a href="{{ $m['url'] }}" target="_blank" rel="noopener" class="btn btn-sm btn-glass">
                    <i class="bi bi-play-circle me-1"></i>{{ $m['original_name'] ?? 'Video' }}
                </a>
                <span class="text-muted-glass small ms-2">{{ $m['created_at'] ?? '' }}</span>
            </div>
            @endforeach
        </div>
        @endif
    @endif
</div>
