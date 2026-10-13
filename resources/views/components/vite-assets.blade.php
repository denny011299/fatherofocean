@props([
    'entries' => ['resources/css/app.css', 'resources/js/app.js'],
])

@php
    \App\Support\ViteAssets::removeStaleHotFile();

    $useBuilt = \App\Support\ViteAssets::useBuiltAssets();
    $builtOk = false;
@endphp

@if ($useBuilt)
    @foreach ($entries as $entry)
        @if (str_ends_with($entry, '.css'))
            @php($href = \App\Support\ViteAssets::builtAssetUrl($entry))
            @if ($href)
                @php($builtOk = true)
                <link rel="stylesheet" href="{{ $href }}">
            @endif
        @elseif (str_ends_with($entry, '.js'))
            @php($src = \App\Support\ViteAssets::builtAssetUrl($entry))
            @if ($src)
                @php($builtOk = true)
                <script type="module" src="{{ $src }}"></script>
            @endif
        @endif
    @endforeach
@endif

{{-- Manifest hilang / kosong: jangan biarkan halaman tanpa CSS diam-diam. --}}
@if (! $useBuilt || ! $builtOk)
    @vite($entries)
@endif
