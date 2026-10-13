#!/usr/bin/env bash
# Production deploy checklist (ship.okejob.id / Linux)
# Run from project root after git pull. Requires: lang/ in the repo (not in .gitignore).

set -euo pipefail

echo "==> 1. Verify lang/ is present (required for i18n)"
test -f lang/id/nav.php || { echo "FATAL: lang/id/nav.php missing — commit and push lang/"; exit 1; }
test -f lang/en/nav.php || { echo "FATAL: lang/en/nav.php missing"; exit 1; }
test -f lang/zh/nav.php || { echo "FATAL: lang/zh/nav.php missing"; exit 1; }

echo "==> 2. Composer & frontend assets"
composer install --no-dev --optimize-autoloader
npm ci
npm run build
# CRITICAL: public/hot forces browsers to load CSS/JS from the Vite dev server
# (e.g. http://127.0.0.1:5173). If this file exists on production, login and
# other pages render unstyled (Times New Roman, no Bootstrap) on iPad/iPhone.
rm -f public/hot
test -f public/build/manifest.json || { echo "FATAL: public/build/manifest.json missing — run npm run build"; exit 1; }

echo "==> 3. Laravel optimize (clear stale caches first)"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
# Verify APP_URL in .env matches canonical site URL before config:cache (wrong URL → 419 login).
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> 4. Remove accidental resources/lang (empty publish breaks some setups)"
if [ -d resources/lang ] && [ -z "$(ls -A resources/lang 2>/dev/null)" ]; then
  rm -rf resources/lang
fi

echo "==> 5. Translation health check"
php artisan translations:health --locale=id

echo "==> 6. Migrations (if applicable)"
# php artisan migrate --force

echo "Done. Confirm APP_LOCALE=id in .env and locale switcher works in browser."
