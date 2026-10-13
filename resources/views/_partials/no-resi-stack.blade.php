@php
    $lines = [];
    if (is_array($value ?? null)) {
        $lines = array_values(array_filter(array_map(static fn ($line) => trim((string) $line), $value)));
    } elseif (is_string($value ?? null) && $value !== '') {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $lines = array_values(array_filter(array_map(static fn ($line) => trim((string) $line), $decoded)));
        } else {
            $lines = [trim($value)];
        }
    }
@endphp

@if ($lines === [])
    <span class="text-muted">-</span>
@else
    <div class="barang-resi-stack tracking-resi-stack">
        @foreach ($lines as $line)
            <div class="barang-resi-line">{{ $line }}</div>
        @endforeach
    </div>
@endif
