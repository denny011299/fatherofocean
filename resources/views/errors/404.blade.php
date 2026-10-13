<!DOCTYPE html>
<html lang="{{ config('locales.html_lang.' . app()->getLocale(), 'id') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — {{ __('errors.404_title') }}</title>
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
            background: linear-gradient(135deg, #0A2240, #E88824);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: .5rem;
        }
        .error-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(10,34,64,.15), rgba(232,136,36,.15));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            font-size: 2rem;
            color: #E88824;
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
            box-shadow: 0 4px 16px rgba(232,136,36,.3);
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
            <i class="bi bi-compass-fill"></i>
        </div>
        <div class="error-code">404</div>
        <h1 class="error-title">{{ __('errors.404_title') }}</h1>
        <p class="error-desc">{{ __('errors.404_desc') }}</p>
        <div class="error-actions">
            <a href="{{ url()->previous() }}" class="btn-back btn-back-ghost">
                <i class="bi bi-arrow-left"></i> {{ __('errors.go_back') }}
            </a>
            <a href="{{ url('/') }}" class="btn-back btn-back-primary">
                <i class="bi bi-house-fill"></i> {{ __('errors.go_home') }}
            </a>
        </div>
    </div>
</body>
</html>
