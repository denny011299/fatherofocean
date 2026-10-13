#!/usr/bin/env bash
# Wrapper cron cPanel → backup otomatis ke storage/app/backups
set -euo pipefail

HOME_DIR="${HOME:-/home/ocew9596}"
APP_DIR="${APP_DIR:-$HOME_DIR/public_html}"
PHP_BIN="${PHP_BIN:-php}"

cd "$APP_DIR"
exec "$PHP_BIN" artisan backup:run >> storage/logs/backup-cron.log 2>&1
