<!DOCTYPE html>
<html lang="{{ config('locales.html_lang.' . app()->getLocale(), 'id') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="2;url={{ route('login') }}">
    <title>{{ __('auth.session_expired') }} — {{ config('app.name') }}</title>
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
        .error-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(245,158,11,.15), rgba(251,191,36,.15));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            font-size: 2rem;
            color: #d97706;
        }
        .error-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: .75rem;
        }
        .error-desc {
            color: var(--text-muted);
            font-size: .9rem;
            line-height: 1.6;
            margin-bottom: 2rem;
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
            background: linear-gradient(135deg, var(--accent), #F5B84A);
            color: #fff;
        }
    </style>
</head>
<body data-theme="light">
    <div class="error-card">
        <div class="error-icon">
            <i class="bi bi-clock-history"></i>
        </div>
        <h1 class="error-title">{{ __('auth.session_expired') }}</h1>
        <p class="error-desc">{{ __('auth.session_expired_login_hint') }}</p>
        <a href="{{ route('login') }}" class="btn-back">
            <i class="bi bi-box-arrow-in-right"></i> {{ __('auth.sign_in') }}
        </a>
    </div>
</body>
</html>
