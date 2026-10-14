<!DOCTYPE html>
<html lang="{{ config('locales.html_lang.' . app()->getLocale(), app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('nav.dashboard')) — {{ config('app.name') }}</title>
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
            if (window.matchMedia('(min-width: 992px)').matches && localStorage.getItem('sidebar-collapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        })();
    </script>
    <script>
        /* Fallback if Vite module loads after DOMContentLoaded */
        (function () {
            function bindCollapseFallback() {
                if (typeof window.toggleSidebarCollapse !== 'function') {
                    return;
                }
                document.querySelectorAll('.js-sidebar-collapse-btn').forEach(function (btn) {
                    if (btn.dataset.sidebarBound === '1') {
                        return;
                    }
                    btn.dataset.sidebarBound = '1';
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        window.toggleSidebarCollapse();
                    });
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bindCollapseFallback);
            } else {
                bindCollapseFallback();
            }
            window.addEventListener('load', bindCollapseFallback);
        })();
    </script>
    <div id="global-preloader">
        <div class="loader-spinner"></div>
    </div>
    <script>
        (function () {
            function forceHidePreloader() {
                var preloader = document.getElementById('global-preloader');
                if (!preloader || preloader.dataset.dismissed === '1') {
                    return;
                }
                preloader.dataset.dismissed = '1';
                preloader.classList.add('fade-out');
                window.setTimeout(function () {
                    if (preloader && preloader.parentNode) {
                        preloader.parentNode.removeChild(preloader);
                    }
                }, 450);
            }

            // Safety net: if main JS fails/blocked, preloader must still disappear.
            window.setTimeout(forceHidePreloader, 4000);
            window.addEventListener('load', forceHidePreloader, { once: true });
            window.addEventListener('pageshow', forceHidePreloader);
        })();
    </script>

    {{-- Sidebar --}}
    <aside class="sidebar" id="sidebar">
        <div class="brand sidebar-brand">
            @if(\App\Support\Branding::hasLogo())
                <div class="sidebar-brand-logo-wrap sidebar-brand-logo-wrap--full">
                    <x-brand-mark variant="sidebar-full" />
                </div>
            @else
                <div class="sidebar-brand-default">
                    <span class="sidebar-brand-logo-wrap sidebar-brand-logo-wrap--compact">
                        <x-brand-mark variant="sidebar" />
                    </span>
                    <div class="sidebar-brand-text">
                        <span class="sidebar-brand-label">{{ __('nav.brand_sub') }}</span>
                        <span class="sidebar-brand-name">
                            {{ __('nav.brand_title') }}
                            <span class="badge-holding-pill">HOLDING</span>
                        </span>
                    </div>
                </div>
            @endif
        </div>

        <nav class="flex-grow-1 py-2">
            <div class="nav-section">{{ __('nav.main_menu') }}</div>
            <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-grid-1x2-fill"></i> {{ __('nav.dashboard') }}
            </a>

            @can('view marking')
            <a href="{{ route('marking.index') }}" class="nav-link {{ request()->routeIs('marking.*') ? 'active' : '' }}">
                <i class="bi bi-tags-fill"></i> {{ __('nav.marking') }}
            </a>
            @endcan

            {{-- Manajemen User --}}
            @can('manage user')
            @php $userOpen = request()->routeIs('user.*') || request()->routeIs('roles.*'); @endphp
            <a href="javascript:void(0)" class="nav-link nav-parent {{ $userOpen ? 'open' : '' }}" onclick="toggleSubmenu('menuUser', this)">
                <i class="bi bi-people-fill"></i> {{ __('nav.user_management') }}
                <i class="bi bi-chevron-right nav-chevron ms-auto"></i>
            </a>
            <div class="nav-children" id="menuUser" {!! $userOpen ? '' : 'style="display:none"' !!}>
                <a href="{{ route('user.index') }}" class="nav-link nav-child {{ request()->routeIs('user.*') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.user_list') }}
                </a>
                @can('manage roles')
                <a href="{{ route('roles.index') }}" class="nav-link nav-child {{ request()->routeIs('roles.*') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.roles_permissions') }}
                </a>
                @endcan
            </div>
            @endcan

            {{-- Pre-Load --}}
            @canany(['view barang', 'view container'])
            @php $opsOpen = request()->routeIs('barang.*') || request()->routeIs('container.*') || request()->routeIs('bongkar-muat.*'); @endphp
            <a href="javascript:void(0)" class="nav-link nav-parent {{ $opsOpen ? 'open' : '' }}" onclick="toggleSubmenu('menuOps', this)">
                <i class="bi bi-boxes"></i> {{ __('nav.operations') }}
                <i class="bi bi-chevron-right nav-chevron ms-auto"></i>
            </a>
            <div class="nav-children" id="menuOps" {!! $opsOpen ? '' : 'style="display:none"' !!}>
                @can('view barang')
                <a href="{{ route('barang.index') }}" class="nav-link nav-child {{ request()->routeIs('barang.*') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.shipping_management') }}
                </a>
                @endcan
                @can('view container')
                <a href="{{ route('container.index') }}" class="nav-link nav-child {{ request()->routeIs('container.*') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.container_loading') }}
                </a>
                @endcan
                @can('view bongkar muat')
                <a href="{{ route('bongkar-muat.index') }}" class="nav-link nav-child {{ request()->routeIs('bongkar-muat.*') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.bongkar_muat') }}
                </a>
                @endcan
            </div>
            @endcanany

            {{-- Tracking (staff only — customer punya menu sendiri di portal) --}}
            @can('view tracking')
            @unlessrole('customer')
            <a href="{{ route('tracking.staff') }}" class="nav-link {{ request()->routeIs('tracking.staff') ? 'active' : '' }}">
                <i class="bi bi-geo-alt-fill"></i> {{ __('nav.item_tracking') }}
            </a>
            @endunlessrole
            @endcan

            {{-- Keuangan (section terpisah, bukan submenu gabung) --}}
            @canany(['view pricing', 'view invoice', 'view sales revenue', 'export omset', 'view pengajuan', 'submit repricing', 'approve repricing', 'change price'])
            @php
                $pengajuanBadge = 0;
                if (auth()->check()) {
                    if (auth()->user()->can('approve repricing')) {
                        $pengajuanBadge = \App\Models\RepricingRequest::query()->where('status', \App\Models\RepricingRequest::STATUS_PENDING)->count();
                    } elseif (auth()->user()->can('submit repricing') || auth()->user()->can('change price')) {
                        $pengajuanBadge = \App\Models\RepricingRequest::query()
                            ->where('status', \App\Models\RepricingRequest::STATUS_PENDING)
                            ->where('submitted_by', auth()->id())
                            ->count();
                    }
                }
            @endphp
            <div class="nav-section">{{ __('nav.finance') }}</div>
            @canany(['view pricing', 'view pengajuan', 'submit repricing', 'approve repricing', 'change price'])
            <a href="{{ route('finance.pricing') }}" class="nav-link {{ request()->routeIs('finance.pricing') || request()->routeIs('finance.pengajuan*') ? 'active' : '' }}">
                <i class="bi bi-lightning-charge-fill"></i> {{ __('nav.instant_pricing') }}
                <span id="navPengajuanBadge" class="badge rounded-pill bg-danger ms-auto {{ $pengajuanBadge > 0 ? '' : 'd-none' }}" style="font-size:.65rem">{{ $pengajuanBadge > 99 ? '99+' : $pengajuanBadge }}</span>
            </a>
            @endcanany
            @can('view invoice')
            <a href="{{ route('finance.index') }}" class="nav-link {{ request()->routeIs('finance.index') ? 'active' : '' }}">
                <i class="bi bi-receipt-cutoff"></i> {{ __('nav.invoice_payment') }}
            </a>
            @endcan
            @canany(['view claim', 'create claim', 'approve claim', 'reject claim'])
            @unlessrole('customer')
            <a href="{{ route('claims.index') }}" class="nav-link {{ request()->routeIs('claims.*') ? 'active' : '' }}">
                <i class="bi bi-shield-exclamation"></i> {{ __('nav.claims') }}
            </a>
            @endunlessrole
            @endcanany
            @canany(['manage sales incentive master', 'manage master data'])
            <a href="{{ route('master-data.page', 'sales-incentive-tiers') }}" class="nav-link {{ request()->is('master-data/sales-incentive-tiers') ? 'active' : '' }}">
                <i class="bi bi-award-fill"></i> {{ __('nav.sales_incentive_master') }}
            </a>
            @endcanany
            @can('view sales revenue')
            <a href="{{ route('sales-revenue.index') }}" class="nav-link {{ request()->routeIs('sales-revenue.*') || request()->routeIs('finance.salary.*') ? 'active' : '' }}">
                <i class="bi bi-cash-stack"></i> {{ __('nav.sales_revenue') }}
            </a>
            @unlessrole('sales|supervisor')
            <a href="{{ route('finance.company-revenue.index') }}" class="nav-link {{ request()->routeIs('finance.company-revenue.*') || request()->routeIs('finance.earnings.company-data') ? 'active' : '' }}">
                <i class="bi bi-buildings-fill"></i> {{ __('nav.company_revenue') }}
            </a>
            @endunlessrole
            @endcan
            @can('export omset')
            <a href="{{ route('export.omset') }}" class="nav-link {{ request()->routeIs('export.omset*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-bar-graph-fill"></i> {{ __('nav.export_revenue') }}
            </a>
            @endcan
            @endcanany

            {{-- Master Data --}}
            @can('manage master data')
            @php
                $mdAny = request()->is('master-data/*');
                $mdOps = request()->is('master-data/branches') || request()->is('master-data/departments') || request()->is('master-data/routes') || request()->is('master-data/gudang-china') || request()->is('master-data/pricing-items') || request()->is('master-data/promos') || request()->is('master-data/sales-incentive-tiers');
                $mdLokasi = request()->is('master-data/countries') || request()->is('master-data/provinces') || request()->is('master-data/cities') || request()->is('master-data/districts');
                $mdBarang = request()->is('master-data/container-types') || request()->is('master-data/packaging-types') || request()->is('master-data/packings') || request()->is('master-data/cbm-types') || request()->is('master-data/classifications') || request()->is('master-data/count-units');
                $mdMitra = request()->is('master-data/vessels') || request()->is('master-data/ports');
            @endphp
            <div class="nav-section">{{ __('nav.master_data') }}</div>

            <a href="javascript:void(0)" class="nav-link nav-parent {{ $mdOps ? 'open' : '' }}" onclick="toggleSubmenu('mdOps', this)">
                <i class="bi bi-sliders2-vertical"></i> {{ __('master_data.group_operational') }}
                <i class="bi bi-chevron-right nav-chevron ms-auto"></i>
            </a>
            <div class="nav-children" id="mdOps" {!! $mdOps ? '' : 'style="display:none"' !!}>
                <a href="{{ route('master-data.page', 'branches') }}" class="nav-link nav-child {{ request()->is('master-data/branches') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.branches') }}
                </a>
                <a href="{{ route('master-data.page', 'departments') }}" class="nav-link nav-child {{ request()->is('master-data/departments') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.departments') }}
                </a>
                <a href="{{ route('master-data.page', 'routes') }}" class="nav-link nav-child {{ request()->is('master-data/routes') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.routes') }}
                </a>
                <a href="{{ route('master-data.page', 'gudang-china') }}" class="nav-link nav-child {{ request()->is('master-data/gudang-china') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.china_warehouse') }}
                </a>
                <a href="{{ route('master-data.page', 'pricing-items') }}" class="nav-link nav-child {{ request()->is('master-data/pricing-items') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.pricing_items') }}
                </a>
                @canany(['manage master data', 'view promo master'])
                <a href="{{ route('master-data.page', 'promos') }}" class="nav-link nav-child {{ request()->is('master-data/promos') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.promos') }}
                </a>
                @endcanany
                @canany(['manage sales incentive master', 'manage master data'])
                <a href="{{ route('master-data.page', 'sales-incentive-tiers') }}" class="nav-link nav-child {{ request()->is('master-data/sales-incentive-tiers') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.sales_incentive_master') }}
                </a>
                @endcanany
            </div>

            <a href="javascript:void(0)" class="nav-link nav-parent {{ $mdLokasi ? 'open' : '' }}" onclick="toggleSubmenu('mdLokasi', this)">
                <i class="bi bi-pin-map-fill"></i> {{ __('nav.location') }}
                <i class="bi bi-chevron-right nav-chevron ms-auto"></i>
            </a>
            <div class="nav-children" id="mdLokasi" {!! $mdLokasi ? '' : 'style="display:none"' !!}>
                <a href="{{ route('master-data.page', 'countries') }}" class="nav-link nav-child {{ request()->is('master-data/countries') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.countries') }}
                </a>
                <a href="{{ route('master-data.page', 'provinces') }}" class="nav-link nav-child {{ request()->is('master-data/provinces') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.provinces') }}
                </a>
                <a href="{{ route('master-data.page', 'cities') }}" class="nav-link nav-child {{ request()->is('master-data/cities') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.cities') }}
                </a>
                <a href="{{ route('master-data.page', 'districts') }}" class="nav-link nav-child {{ request()->is('master-data/districts') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.districts') }}
                </a>
            </div>

            <a href="javascript:void(0)" class="nav-link nav-parent {{ $mdBarang ? 'open' : '' }}" onclick="toggleSubmenu('mdBarang', this)">
                <i class="bi bi-box-seam-fill"></i> {{ __('nav.cargo_data') }}
                <i class="bi bi-chevron-right nav-chevron ms-auto"></i>
            </a>
            <div class="nav-children" id="mdBarang" {!! $mdBarang ? '' : 'style="display:none"' !!}>
                <a href="{{ route('master-data.page', 'container-types') }}" class="nav-link nav-child {{ request()->is('master-data/container-types') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.container_types') }}
                </a>
                <a href="{{ route('master-data.page', 'packaging-types') }}" class="nav-link nav-child {{ request()->is('master-data/packaging-types') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.packaging') }}
                </a>
                <a href="{{ route('master-data.page', 'packings') }}" class="nav-link nav-child {{ request()->is('master-data/packings') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.packing') }}
                </a>
                <a href="{{ route('master-data.page', 'cbm-types') }}" class="nav-link nav-child {{ request()->is('master-data/cbm-types') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.cbm_types') }}
                </a>
                <a href="{{ route('master-data.page', 'classifications') }}" class="nav-link nav-child {{ request()->is('master-data/classifications') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.classifications') }}
                </a>
                <a href="{{ route('master-data.page', 'count-units') }}" class="nav-link nav-child {{ request()->is('master-data/count-units') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.count_units') }}
                </a>
            </div>

            <a href="javascript:void(0)" class="nav-link nav-parent {{ $mdMitra ? 'open' : '' }}" onclick="toggleSubmenu('mdMitra', this)">
                <i class="bi bi-ship"></i> {{ __('nav.partners_fleet') }}
                <i class="bi bi-chevron-right nav-chevron ms-auto"></i>
            </a>
            <div class="nav-children" id="mdMitra" {!! $mdMitra ? '' : 'style="display:none"' !!}>
                <a href="{{ route('master-data.page', 'vessels') }}" class="nav-link nav-child {{ request()->is('master-data/vessels') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.vessels') }}
                </a>
                <a href="{{ route('master-data.page', 'ports') }}" class="nav-link nav-child {{ request()->is('master-data/ports') ? 'active' : '' }}">
                    <span class="nav-dot"></span> {{ __('nav.ports') }}
                </a>
            </div>
            @endcan

            {{-- Portal Customer --}}
            @role('customer')
            <div class="nav-section">{{ __('nav.my_portal') }}</div>
            <a href="{{ route('tracking.index') }}" class="nav-link {{ request()->routeIs('tracking.index') ? 'active' : '' }}">
                <i class="bi bi-geo-alt-fill"></i> {{ __('nav.item_tracking') }}
            </a>
            <a href="{{ route('billing.index') }}" class="nav-link {{ request()->routeIs('billing.*') ? 'active' : '' }}">
                <i class="bi bi-receipt-cutoff"></i> {{ __('nav.my_billing') }}
            </a>
            @canany(['view claim', 'create claim', 'approve claim', 'reject claim'])
            <a href="{{ route('claims.index') }}" class="nav-link {{ request()->routeIs('claims.*') ? 'active' : '' }}">
                <i class="bi bi-shield-exclamation"></i> {{ __('nav.claims') }}
            </a>
            @endcanany
            @endrole

            @can('view activity log')
            <div class="nav-section">{{ __('nav.system') }}</div>
            <a href="{{ route('activity.index') }}" class="nav-link {{ request()->routeIs('activity.*') ? 'active' : '' }}">
                <i class="bi bi-clock-history"></i> {{ __('nav.activity_log') }}
            </a>
            @endcan
            @can('manage system')
            <a href="{{ route('system.index') }}" class="nav-link {{ request()->routeIs('system.*') ? 'active' : '' }}">
                <i class="bi bi-gear-wide-connected"></i> {{ __('nav.settings') }}
            </a>
            @endcan

        </nav>

        <div class="sidebar-user-footer">
            <div class="sidebar-user-card d-flex align-items-center gap-2">
                <a href="{{ route('profile.index') }}" class="position-relative d-inline-block text-decoration-none flex-shrink-0" title="{{ __('nav.my_profile') }}">
                    <div class="sidebar-avatar d-flex align-items-center justify-content-center">
                        <i class="bi bi-person-fill text-white"></i>
                    </div>
                    <span class="sidebar-online-dot"></span>
                </a>
                <a href="{{ route('profile.index') }}" class="flex-grow-1 overflow-hidden text-decoration-none sidebar-user-meta" title="{{ __('nav.my_profile') }}">
                    <div class="text-white fw-600 small text-truncate">{{ auth()->user()->name }}</div>
                    <div class="sidebar-user-role text-truncate">{{ auth()->user()->roles->first()?->name ?? 'user' }}</div>
                </a>
                <a href="{{ route('logout') }}" class="sidebar-logout-btn flex-shrink-0" title="{{ __('nav.logout') }}">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </aside>

    {{-- Sidebar backdrop (mobile) --}}
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

    {{-- Main Content --}}
    <div class="main-content">
        <header class="topbar">
            <div class="d-flex align-items-center gap-2 gap-md-3">
                <button type="button" class="btn btn-sm btn-glass d-lg-none" onclick="toggleSidebar()" aria-label="{{ __('nav.sidebar_mobile') }}">
                    <i class="bi bi-list"></i>
                </button>
                <button type="button"
                        id="sidebarCollapseBtn"
                        class="btn btn-sm btn-glass js-sidebar-collapse-btn d-none d-lg-inline-flex"
                        data-title-collapse="{{ __('nav.sidebar_collapse') }}"
                        data-title-expand="{{ __('nav.sidebar_expand') }}"
                        aria-expanded="true"
                        aria-controls="sidebar">
                    <i class="bi bi-arrow-bar-left" data-sidebar-collapse-icon aria-hidden="true"></i>
                </button>
                <div class="d-flex align-items-center gap-2">
                    <h1 class="page-title">@yield('title', __('nav.dashboard'))</h1>
                    <span class="topbar-holding-pill d-none d-xl-inline-flex align-items-center gap-1">
                        <i class="bi bi-diagram-3-fill"></i> Central Holding
                    </span>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                @auth
                <x-daily-rate-header />
                @endauth
                @can('view barang')
                <x-notification-bell />
                @endcan
                @yield('topbar-actions')
                <x-language-switcher />
                <button class="theme-toggle" id="themeToggle" title="{{ __('nav.toggle_theme') }}">
                    <i class="bi bi-sun-fill"></i>
                    <i class="bi bi-moon-fill"></i>
                </button>
                <a href="{{ route('logout') }}" class="theme-toggle topbar-logout text-decoration-none" title="{{ __('nav.logout') }}" aria-label="{{ __('nav.logout') }}">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </header>

        <main class="p-3 p-md-4">
            @if(session('success'))
                <div class="alert alert-success glass border-0 text-white" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger glass border-0 text-white" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    {{-- Toast Container --}}
    <div id="toast-container" class="position-fixed top-0 end-0 p-2 p-md-3" style="z-index:9999"></div>

    <script>
        document.getElementById('themeToggle').addEventListener('click', function() {
            var current = document.body.getAttribute('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';
            document.body.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        });
    </script>

    @auth
    <script>
        window.__dailyRate = @json(\App\Support\DailyRate::current());
    </script>
    @endauth
    @php
        $layoutJsLocaleGroups = ['common', 'swal', 'settings', 'notifications'];
        if (request()->routeIs('container.*')) {
            $layoutJsLocaleGroups = ['container', 'messages', 'common', 'swal'];
        }
        $layoutJsLocale = \App\Support\JsTranslations::merge($layoutJsLocaleGroups);
    @endphp
    <script>
        window.__t = @json($layoutJsLocale);
    </script>
    @stack('scripts')
</body>
</html>
