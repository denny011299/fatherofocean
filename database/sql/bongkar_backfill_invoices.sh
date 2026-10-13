#!/usr/bin/env bash
# Backfill draft invoice untuk barang status bongkar tanpa invoice_items.
# Jalankan dari server/app root (folder yang berisi artisan).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

echo "==> Preview (dry-run)"
php artisan invoices:backfill-bongkar --dry-run

echo ""
echo "==> Membuat / menggabungkan draft invoice"
php artisan invoices:backfill-bongkar

echo ""
echo "==> Selesai. Verifikasi dengan query A1 di database/sql/bongkar_missing_invoices.sql"
echo "    Jika masih ada eligible, ulangi: php artisan invoices:backfill-bongkar"
