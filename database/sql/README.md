# SQL scripts — Pelayaran

Skrip di folder ini untuk diagnostik dan patch manual MySQL. **Schema resmi tetap lewat `php artisan migrate`.**

### Kolom container (penting untuk SQL)

| Lama (pre-migrate) | Sekarang | Label di query SQL |
|--------------------|----------|-------------------|
| `containers.code` | `containers.partai` | Batch / partai (UI: BATCH) |
| `containers.seal_no` | `containers.no_bl` | No. BL |
| `containers.vessel_id` | `containers.port_id` | Port |

Query diagnostik memakai `COALESCE(NULLIF(TRIM(container_no), ''), partai)` untuk menampilkan container — **jangan** pakai `c.code` di DB yang sudah migrate.

## Barang bongkar tanpa invoice (tagihan)

| File | Fungsi |
|------|--------|
| [bongkar_missing_invoices.sql](bongkar_missing_invoices.sql) | Part A: query cek semua bongkar tanpa invoice (read-only). Part B–C: artisan & perbaikan data |
| [invoice_bongkar_container.sql](invoice_bongkar_container.sql) | Bongkar **di container** tanpa invoice: ringkasan, detail, alasan blokir, agregat per container |
| [bongkar_backfill_invoices.sh](bongkar_backfill_invoices.sh) | Satu perintah: dry-run lalu backfill |

### Cek (MySQL)

Jalankan query **A1** (ringkasan) dan **A5** (daftar siap diproses) dari `bongkar_missing_invoices.sql`.

Khusus muat container: **C1** + **C3** dari `invoice_bongkar_container.sql`, lalu backfill dengan `--in-container`.

### Buat tagihan otomatis (disarankan)

```bash
cd /path/to/pelayaran
php artisan invoices:backfill-bongkar --dry-run
php artisan invoices:backfill-bongkar
```

Atau:

```bash
bash database/sql/bongkar_backfill_invoices.sh
```

Default memproses **500** barang per run (`--limit=500`). Ulangi perintah sampai dry-run menampilkan *No bongkar barangs need invoice backfill*.

Filter opsional: `--user=123`, `--marking=ABC`, `--in-container`, `--limit=100`.

### Cron (opsional)

Tambahkan di `bootstrap/app.php` (atau jadwalkan di server):

```php
->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
    $schedule->command('invoices:backfill-bongkar')->dailyAt('02:00')->withoutOverlapping();
})
```

Pastikan `* * * * * php /path/to/artisan schedule:run` aktif.

### Mengapa ada barang yang dilewati

- `user_id` NULL — tidak ada customer untuk invoice
- `price_per_cbm` ≤ 0 — belum ada harga CBM
- Sudah ada baris di `invoice_items` — tidak diproses ulang

**Jangan** `INSERT` ke `invoices` / `invoice_items` manual; gunakan artisan agar `invoice_no`, konsolidasi marking/container, dan `line_total` benar.

### Diagnostik SQL: kolom `db_*` vs `projected_*`

Query **A2** (`bongkar_missing_invoices.sql`) dan **C2** (`invoice_bongkar_container.sql`) memisahkan dua lapisan:

| Kolom | Arti |
|-------|------|
| `barang_user_id`, `db_customer_label`, `db_block_reason` | Keadaan **aktual** tabel `barangs` saja — sama filter artisan / `Barang::invoiceSkipReasonOnBongkar()` (`no_customer`, `no_price`, atau `NULL` = siap backfill). |
| `projected_user_id`, `projected_customer_label`, `projected_block_reason` | Perkiraan setelah PHP/`--dry-run` mengisi dari **marking** + `marking_pricings` (`projected_eligible` = dry-run bisa proses). |

Baris bisa punya `projected_user_id` dan nama customer dari marking, tetapi `db_block_reason = no_customer` karena `barangs.user_id` masih NULL — itu **bukan bug**, melainkan beda DB vs proyeksi. Query **C5** / **A3** khusus baris terblokir di DB yang projected-nya eligible.
