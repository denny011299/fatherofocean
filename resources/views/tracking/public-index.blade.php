<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Lacak Pengiriman — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <x-vite-assets :entries="['resources/css/app.css']" />
</head>
<body class="public-track-entry" data-theme="light">
    <script>
        (function(){ var t = localStorage.getItem('theme') || 'light'; document.body.setAttribute('data-theme', t); })();
    </script>

    <div class="public-track-entry-bg" aria-hidden="true">
        <div class="public-track-entry-bg-topbar">
            <span class="public-track-entry-bg-brand">
                @if(\App\Support\Branding::hasLogo())
                    <span class="sidebar-brand-logo-wrap sidebar-brand-logo-wrap--compact"><x-brand-mark variant="public-topbar-full" /></span>
                @else
                    <x-brand-mark variant="public-entry-bg" /> {{ __('nav.brand_title') }}
                @endif
            </span>
            <span class="public-track-entry-bg-pill"></span>
        </div>
        <div class="public-track-entry-bg-layout">
            <div class="public-track-entry-bg-sidebar">
                <div class="public-track-entry-bg-avatar"></div>
                <div class="public-track-entry-bg-line long"></div>
                <div class="public-track-entry-bg-line"></div>
                <div class="public-track-entry-bg-line"></div>
                <div class="public-track-entry-bg-line short"></div>
            </div>
            <div class="public-track-entry-bg-main">
                <div class="public-track-entry-bg-hero"></div>
                <div class="public-track-entry-bg-meta">
                    <div class="public-track-entry-bg-chip"></div>
                    <div class="public-track-entry-bg-chip"></div>
                    <div class="public-track-entry-bg-chip"></div>
                    <div class="public-track-entry-bg-chip"></div>
                </div>
                <div class="public-track-entry-bg-timeline">
                    <div class="public-track-entry-bg-tl-row">
                        <div class="public-track-entry-bg-tl-date"></div>
                        <div class="public-track-entry-bg-tl-dot"></div>
                        <div class="public-track-entry-bg-tl-text"></div>
                    </div>
                    <div class="public-track-entry-bg-tl-row">
                        <div class="public-track-entry-bg-tl-date"></div>
                        <div class="public-track-entry-bg-tl-dot"></div>
                        <div class="public-track-entry-bg-tl-text"></div>
                    </div>
                    <div class="public-track-entry-bg-tl-row">
                        <div class="public-track-entry-bg-tl-date"></div>
                        <div class="public-track-entry-bg-tl-dot"></div>
                        <div class="public-track-entry-bg-tl-text"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="public-track-entry-overlay"></div>

    <div class="public-track-entry-card glass">
        <div class="public-track-entry-card-top">
            <button class="theme-toggle" id="themeToggle" title="Toggle Light/Dark">
                <i class="bi bi-sun-fill"></i>
                <i class="bi bi-moon-fill"></i>
            </button>
        </div>

        <div class="public-track-entry-brand {{ \App\Support\Branding::hasLogo() ? 'public-track-entry-brand--logo-only' : '' }}">
            @if(\App\Support\Branding::hasLogo())
                <div class="sidebar-brand-logo-wrap public-track-entry-brand-logo">
                    <x-brand-mark variant="public-entry-full" />
                </div>
            @else
                <div class="public-track-entry-brand-icon">
                    <x-brand-mark variant="public-entry" />
                </div>
                <h1 class="public-track-entry-title">{{ __('nav.brand_title') }}</h1>
                <p class="public-track-entry-subtitle">{{ __('nav.brand_sub') }} — Lacak Pengiriman Anda</p>
            @endif
        </div>

        @if(session('status'))
            <div class="public-track-entry-status">
                <i class="bi bi-check-circle me-1"></i>{{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('track.search') }}" class="public-track-entry-form">
            @csrf
            <div class="mb-3">
                <label for="no_index" class="form-label">{{ __('tracking.col_no_index') }}</label>
                <input
                    type="text"
                    id="no_index"
                    name="no_index"
                    class="form-control"
                    placeholder="{{ __('tracking.public_index_placeholder') }}"
                    value="{{ old('no_index', $prefillIndex) }}"
                    autocomplete="off"
                    autofocus
                    required
                >
                @error('no_index')
                    <div class="public-track-entry-error"><i class="bi bi-exclamation-circle me-1"></i>{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn btn-accent public-track-entry-submit">
                <i class="bi bi-search me-2"></i>Lacak Pengiriman
            </button>
        </form>

        <p class="public-track-entry-footer">
            <i class="bi bi-shield-lock me-1"></i>{{ __('tracking.public_index_hint') }}
        </p>
    </div>

    <script>
        document.getElementById('themeToggle').addEventListener('click', function() {
            var current = document.body.getAttribute('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';
            document.body.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        });
    </script>
</body>
</html>
