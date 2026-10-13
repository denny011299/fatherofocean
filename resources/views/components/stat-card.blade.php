@props([
    'icon' => 'bi-box-seam',
    'label' => 'Stat',
    'value' => 0,
    'color' => '',
    'sub' => null,
    'cols' => 'col-6 col-md-3',
])

<div class="{{ $cols }}">
    <div class="glass stat-card">
        <div class="stat-icon"><i class="bi {{ $icon }}"></i></div>
        <div class="stat-label">{{ $label }}</div>
        <div class="stat-value {{ $color }}">{{ $value }}</div>
        @if($sub)
        <div class="stat-change text-muted-glass">{{ $sub }}</div>
        @endif
    </div>
</div>
