# EMERGENCY: Restore Missing Laravel Core Files

**Production:** `/home/ocew9596/public_html/` (oceanlink.my.id)  
**Error:** `include(.../app/Providers/AppServiceProvider.php): Failed to open stream`

Partial deploy / rollback deleted core files. Upload these **immediately** via cPanel File Manager or SFTP.

---

## Priority 1 — Upload NOW (site cannot boot without these)

| File | Local path | Server path |
|------|------------|-------------|
| **AppServiceProvider** | `app/Providers/AppServiceProvider.php` | `public_html/app/Providers/AppServiceProvider.php` |
| **Provider manifest** | `bootstrap/providers.php` | `public_html/bootstrap/providers.php` |
| **App bootstrap** | `bootstrap/app.php` | `public_html/bootstrap/app.php` |
| **Front controller** | `public/index.php` | `public_html/public/index.php` or docroot `index.php` |
| **Artisan CLI** | `artisan` | `public_html/artisan` |

Create the directory on server if missing:

```bash
mkdir -p app/Providers
chmod 755 app/Providers
```

---

## Priority 2 — Required dependencies of AppServiceProvider

| File | Why |
|------|-----|
| `app/Support/ViteAssets.php` | Called in `AppServiceProvider::boot()` |
| `app/Models/Marking.php` | Route model binding `{marking}` |
| `app/Models/User.php` | `Gate::before` role checks |
| `app/helpers.php` | Composer autoload `files` entry |

---

## Priority 3 — After site boots (clear stale cache)

Via cPanel Terminal or SSH:

```bash
cd /home/ocew9596/public_html
php artisan optimize:clear
# If artisan fails, delete cache files manually:
rm -f bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/packages.php
```

Delete stale Vite dev marker if CSS is broken:

```bash
rm -f public/hot
```

---

## Laravel 11 — What belongs in `app/Providers/`

This project uses **Laravel 11**. Only **one** application provider is required:

| File | Required? |
|------|-----------|
| `AppServiceProvider.php` | **YES** |
| `AuthServiceProvider.php` | No (removed in L11 skeleton) |
| `EventServiceProvider.php` | No (auto-discovery) |
| `RouteServiceProvider.php` | No (routes in `bootstrap/app.php`) |

---

## composer.json autoload (no provider list)

Providers are **not** listed in `composer.json`. Autoload is:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/"
    },
    "files": [
        "app/helpers.php"
    ]
}
```

`AppServiceProvider` is registered via `bootstrap/providers.php`, not Composer.

---

## STRONGLY RECOMMENDED: Full cPanel backup restore

If rollback removed `AppServiceProvider`, other `app/` files may also be missing. Symptoms after partial fix:

- 500 on specific routes
- Missing controllers/models
- Broken permissions or translations

**Safest fix:** cPanel → **Backup** → restore `public_html` from **before** the bad deploy.

Then redeploy only the intended changed files — never delete `app/Providers/` on production.

---

## Verify after upload

```bash
php -l app/Providers/AppServiceProvider.php
php -l bootstrap/providers.php
php artisan about
```

Open: `https://oceanlink.my.id/up` (health check from `bootstrap/app.php`).
