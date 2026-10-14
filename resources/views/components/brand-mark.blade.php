@props([
    'variant' => 'sidebar',
])

@php
    $logoUrl = \App\Support\Branding::logoUrl();
@endphp

@if($logoUrl)
    <img
        src="{{ $logoUrl }}"
        alt="{{ config('app.name') }}"
        class="brand-mark brand-mark--{{ $variant }}"
        {{ $attributes }}
    >
@else
    <i class="bi bi-box-seam-fill brand-mark-fallback brand-mark-fallback--{{ $variant }}" {{ $attributes }}></i>
@endif
