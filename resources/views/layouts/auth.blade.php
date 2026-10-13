<!DOCTYPE html>
<html lang="{{ config('locales.html_lang.' . app()->getLocale(), app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('auth.login')) — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    @php
        $faviconUrl = \App\Support\Branding::logoUrl() ?: asset('images/terima-ref-0.png');
    @endphp
    <link rel="icon" type="image/png" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" type="image/png" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <x-vite-assets :entries="['resources/css/app.css', 'resources/js/app.js']" />
    @stack('styles')
</head>
<body data-theme="light">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || 'light';
            document.body.setAttribute('data-theme', t);
        })();
    </script>
    <div class="auth-wrapper">
        <div class="auth-card glass">
            <div class="text-center mb-4">
                <div class="d-flex justify-content-end gap-2 mb-2">
                    <x-language-switcher />
                    <button class="theme-toggle" id="themeToggle" title="{{ __('nav.toggle_theme') }}">
                        <i class="bi bi-sun-fill"></i>
                        <i class="bi bi-moon-fill"></i>
                    </button>
                </div>
                @if(\App\Support\Branding::hasLogo())
                    <div class="sidebar-brand-logo-wrap sidebar-brand-logo-wrap--auth d-inline-flex mb-3">
                        <x-brand-mark variant="auth-full" />
                    </div>
                @else
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:56px;height:56px;background:linear-gradient(135deg,var(--primary),var(--accent))">
                        <i class="bi bi-tsunami fs-4" style="color:var(--accent-text)"></i>
                    </div>
                    <h4 class="fw-800" style="color:var(--text-primary)">{{ __('nav.brand_sub') }}</h4>
                    <p class="text-muted-glass small">{{ __('auth.tagline') }}</p>
                @endif
            </div>
            @yield('content')
        </div>
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
