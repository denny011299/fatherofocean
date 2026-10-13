<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 — Kesalahan Server</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg-main: #f8fafc;
            --surface-card: #fff;
            --glass-border: rgba(0,0,0,.06);
            --text-primary: #0f172a;
            --text-muted: #94a3b8;
            --accent: #2563eb;
        }
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
            background: linear-gradient(135deg, #ef4444, #dc2626);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: .5rem;
        }
        .error-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(239,68,68,.15), rgba(220,38,38,.15));
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
            background: linear-gradient(135deg, var(--accent), #F5B84A);
            color: #fff;
            border: none;
        }
        .btn-back:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(59,130,246,.3);
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>
        <div class="error-code">500</div>
        <h1 class="error-title">Kesalahan Server</h1>
        <p class="error-desc">
            Terjadi kesalahan pada server. Tim kami sudah diberitahu.
            Silakan coba lagi dalam beberapa saat.
        </p>
        <a href="{{ url('/') }}" class="btn-back">
            <i class="bi bi-house-fill"></i> Halaman Utama
        </a>
    </div>
</body>
</html>
