@php
    $portalMarkings = $portalMarkings ?? collect();
    $portalMarking = $portalMarking ?? null;
    $markings = $portalMarkings instanceof \Illuminate\Support\Collection
        ? $portalMarkings
        : collect($portalMarkings);

    if ($markings->isEmpty() && $portalMarking !== null) {
        $markings = collect([$portalMarking]);
    }
@endphp

@if($markings->isNotEmpty())
<div class="glass p-3 mb-3">
    <div class="text-muted-glass small mb-2">{{ __('tracking.my_markings') }}</div>
    <div class="d-flex flex-wrap gap-2">
        @foreach($markings as $marking)
        <span class="badge-glass px-3 py-2 fw-700" style="font-size:.85rem">{{ $marking->displayCode() }}</span>
        @endforeach
    </div>
    @if($markings->count() > 1)
    <div class="text-muted-glass small mt-2">{{ __('tracking.customer_scope_hint') }}</div>
    @endif
</div>
@endif
