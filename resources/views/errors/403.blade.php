<!DOCTYPE html>
<html lang="{{ config('locales.html_lang.' . app()->getLocale(), 'id') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — Akses Ditolak</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <x-vite-assets :entries="['resources/css/app.css']" />
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
            background: var(--bg-main);
            margin: 0;
            padding: 1rem;
        }
        .error-card {
            text-align: center;
            max-width: 480px;
            width: 100%;
            padding: 3rem 2rem;
            border-radius: 24px;
            background: var(--surface-card);
            border: 1px solid var(--glass-border);
            box-shadow: 0 8px 32px rgba(0,0,0,.08);
        }
        .error-code {
            font-size: 6rem;
            font-weight: 800;
            line-height: 1;
            background: linear-gradient(135deg, #ef4444, #f97316);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: .5rem;
        }
        .error-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(239,68,68,.15), rgba(249,115,22,.15));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            font-size: 2rem;
            color: #ef4444;
        }
        .error-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: .5rem;
        }
        .error-desc {
            color: var(--text-muted);
            font-size: .9rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .error-actions {
            display: flex;
            gap: .75rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .625rem 1.5rem;
            border-radius: 12px;
            font-size: .85rem;
            font-weight: 600;
            text-decoration: none;
            transition: all .2s;
        }
        .btn-back-primary {
            background: linear-gradient(135deg, var(--accent), #F5B84A);
            color: #fff;
            border: none;
        }
        .btn-back-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(59,130,246,.3);
            color: #fff;
        }
        .btn-back-ghost {
            background: var(--btn-glass-bg);
            color: var(--text-secondary);
            border: 1px solid var(--glass-border);
        }
        .btn-back-ghost:hover {
            background: var(--btn-glass-hover-bg);
            color: var(--text-primary);
        }
        .role-badge {
            display: inline-block;
            padding: .2rem .6rem;
            border-radius: 8px;
            background: rgba(59,130,246,.1);
            color: var(--accent);
            font-size: .75rem;
            font-weight: 600;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body data-theme="light">
    <script>
        (function(){
            var t = localStorage.getItem('theme') || 'light';
            document.body.setAttribute('data-theme', t);
        })();
    </script>
    <div class="error-card">
        <div class="error-icon">
            <i class="bi bi-shield-lock-fill"></i>
        </div>
        <div class="error-code">403</div>
        <h1 class="error-title">Akses Ditolak</h1>
        <p class="error-desc">
            Anda tidak memiliki izin untuk mengakses halaman ini.
            Silakan hubungi administrator jika Anda merasa ini adalah kesalahan.
        </p>
        <div class="error-actions">
            <a href="{{ url()->previous() }}" class="btn-back btn-back-ghost">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
            <a href="{{ route('dashboard') }}" class="btn-back btn-back-primary">
                <i class="bi bi-house-fill"></i> Dashboard
            </a>
        </div>
        @auth
        <div class="role-badge">
            <i class="bi bi-person-fill me-1"></i>
            Login sebagai: {{ auth()->user()->name }} ({{ auth()->user()->roles->pluck('name')->implode(', ') }})
        </div>
        @endauth
    </div>
</body>
</html>
