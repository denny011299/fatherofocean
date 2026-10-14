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

    <div class="auth-split">
        <div class="auth-floating-controls">
            <x-language-switcher />
            <button class="theme-toggle" id="themeToggle" title="{{ __('nav.toggle_theme') }}">
                <i class="bi bi-sun-fill"></i>
                <i class="bi bi-moon-fill"></i>
            </button>
        </div>

        {{-- Left: brand panel --}}
        <aside class="auth-brand-panel">
            <div class="auth-brand-top">
                @if(\App\Support\Branding::hasLogo())
                    <div class="sidebar-brand-logo-wrap sidebar-brand-logo-wrap--auth">
                        <x-brand-mark variant="auth-full" />
                    </div>
                @else
                    <span class="auth-brand-mark"><i class="bi bi-box-seam-fill"></i></span>
                    <span class="auth-brand-name">{{ config('app.name', 'EXPORT') }}</span>
                @endif
            </div>

            <div class="auth-brand-body">
                <div class="auth-brand-tag">
                    <i class="bi bi-shield-check"></i>
                    <span>SISTEM INDUK GRUP PERUSAHAAN</span>
                </div>
                <h1 class="auth-brand-headline">
                    Satu sistem untuk seluruh unit ekspor grup.
                </h1>
                <p class="auth-brand-lead">
                    Portal internal untuk mengelola gudang, kontainer, dan pengiriman lintas perusahaan dalam satu kendali terpusat.
                </p>

                <svg class="auth-route" viewBox="0 0 480 90" fill="none" aria-hidden="true">
                    <path id="authRoutePath" d="M 12 66 C 120 66, 150 20, 240 38 S 380 70, 468 24"
                          stroke="currentColor" stroke-opacity=".18" stroke-width="1.5" stroke-linecap="round"/>
                    <path class="auth-route-flow" d="M 12 66 C 120 66, 150 20, 240 38 S 380 70, 468 24"
                          stroke="currentColor" stroke-opacity=".55" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="12" cy="66" r="4" fill="currentColor"/>
                    <circle cx="468" cy="24" r="4" fill="currentColor"/>
                    <circle r="3.5" fill="#38BDF8">
                        <animateMotion dur="9s" repeatCount="indefinite" rotate="auto">
                            <mpath href="#authRoutePath"/>
                        </animateMotion>
                    </circle>
                </svg>
                <div class="auth-route-labels">
                    <span><i class="bi bi-geo-alt-fill me-1"></i> Gudang China (Yiwu/GZ)</span>
                    <span><i class="bi bi-geo-alt-fill me-1"></i> Gudang Indonesia (Distribusi)</span>
                </div>
            </div>

            <div class="auth-brand-foot">
                <span class="auth-live-indicator"></span>
                <span>Jaringan Internal Holding Terproteksi · Akses Terpusat</span>
            </div>
        </aside>

        {{-- Right: form panel --}}
        <main class="auth-form-panel">
            <div class="auth-form-inner">
                <div class="auth-form-mobile-brand">
                    <span class="auth-brand-mark"><i class="bi bi-box-seam-fill"></i></span>
                    <span class="auth-brand-name">{{ config('app.name', 'EXPORT') }}</span>
                </div>

                <div class="auth-form-header">
                    <div class="auth-form-tag">
                        <span class="auth-portal-badge">
                            <i class="bi bi-shield-check"></i> PORTAL INTERNAL EKSPOR
                        </span>
                    </div>
                    <h2 class="auth-form-title">Akses Karyawan & Mitra</h2>
                    <p class="auth-form-subtitle">Satu pintu masuk operasional untuk seluruh unit pelayaran dan mitra terdaftar.</p>
                </div>

                @yield('content')
            </div>
        </main>
    </div>

    <script>
        document.getElementById('themeToggle')?.addEventListener('click', function() {
            var current = document.body.getAttribute('data-theme') || 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            document.body.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        });
    </script>
</body>
</html>
