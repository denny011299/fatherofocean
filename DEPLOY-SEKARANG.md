# DEPLOY SEKARANG — Upload `app/` ke Production

**Tanggal verifikasi:** 20 Juni 2026  
**Status lokal:** `php tools/verify-deploy.php` → **PASS** (168 file PHP di `app/`)  
**Penting:** Upload **SELURUH folder `app/`**, jangan sebagian. Partial upload = error 500 di production.

---

## Ringkasan perbaikan yang sudah dilakukan

| File | Perbaikan |
|------|-----------|
| `app/Support/MarkingPricingResolver.php` | Tambah `modalRatesFromPricingRow()` |
| `app/Support/Branding.php` | Tambah `absoluteLogoPath()` |
| `app/Models/User.php` | Tambah `commissionRateLabel()` |
| `tools/verify-deploy.php` | Deteksi Eloquent scope (`visibleToUser`, `fullyPaid`, dll.) |

**Dihapus (artifact debug — jangan upload ulang):**
- `debug_packing_*.php`, `inspect-*.php`, `test-logo-upload.php`, `generate-test-terima.php`
- `device-manager.blade.php` (salah lokasi)
- `SalesRevenueCalculator_commission_detail.php` (placeholder)

---

## Langkah FileZilla (step-by-step)

### 1. Backup server dulu (jika masih bisa akses panel hosting)

Via cPanel File Manager atau FileZilla, **rename** folder lama:
```
/public_html/app  →  /public_html/app_backup_2026-06-20
```
*(Sesuaikan path — bisa `app`, `laravel/app`, atau di luar public_html tergantung struktur hosting.)*

### 2. Connect FileZilla

| Field | Isi |
|-------|-----|
| Host | IP/domain server Anda |
| Username | user FTP/cPanel |
| Password | password FTP |
| Port | 21 (FTP) atau 22 (SFTP) |

> Jika SSH connection refused, FTP/SFTP via FileZilla tetap bisa dipakai.

### 3. Navigasi ke folder Laravel di server

Cari folder yang berisi `artisan`, `composer.json`, dan subfolder `app/`.  
Contoh umum:
- `/home/username/pelayaran/app/`
- `/var/www/html/app/`

### 4. Upload SELURUH folder `app/` lokal

**Panel kiri (local):** `C:\Pelayaran\app\`  
**Panel kanan (remote):** folder `app/` di server

**Cara upload:**
1. Pilih folder `app` di panel kiri
2. Drag & drop ke panel kanan (ke parent folder `app`, bukan ke dalam subfolder)
3. Saat konfirmasi overwrite → pilih **Overwrite** / **Yes to All**
4. Pastikan **168 file `.php`** ter-upload (termasuk subfolder)

**Struktur wajib setelah upload:**
```
app/
├── Console/
├── Exports/
├── Http/
├── Imports/
├── Jobs/
├── Livewire/
├── Models/
├── Providers/
├── Services/
├── Support/          ← 45 file, jangan ada yang stub
├── helpers.php
```

### 5. Upload juga `tools/verify-deploy.php` (opsional tapi disarankan)

Upload file ini ke server agar bisa verifikasi di production:
```
tools/verify-deploy.php  →  [path-laravel]/tools/verify-deploy.php
```

### 6. Clear cache di server

**Via cPanel Terminal** (jika ada) atau **cron one-liner**:
```bash
cd /path/ke/laravel
php artisan config:clear
php artisan view:clear
php artisan route:clear
php artisan cache:clear
```

Jika tidak ada terminal, buat file sementara `clear-cache.php` di public:
```php
<?php
// HAPUS file ini setelah dipakai!
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->call('config:clear');
$app->make(Illuminate\Contracts\Console\Kernel::class)->call('view:clear');
echo 'OK';
```
Akses via browser sekali, lalu **hapus file tersebut**.

### 7. Verifikasi di server

```bash
php tools/verify-deploy.php
```
Harus muncul: **`PASS — all checks OK`**

### 8. Smoke test manual (browser)

Login sebagai staff, cek halaman ini:

| URL / fitur | Yang dicek |
|-------------|------------|
| Dashboard | Tidak error 500, statistik tampil |
| Barang → tambah/edit | Marking dropdown muncul, simpan OK |
| Marking → template | AJAX pricing template load |
| Export omset | Halaman buka, filter jalan |
| Logo branding | `/branding/logo` atau PDF invoice tampil logo |
| Finance → invoice | Filter "paid" jalan |

---

## File kritis — jangan sampai stub/kosong

| File | Method/API wajib |
|------|------------------|
| `app/Models/Marking.php` | `scopeVisibleToUser`, `scopeWhereBarangMarkingValue`, `restrictBarangsQuery` |
| `app/Models/Invoice.php` | `scopeFullyPaid` |
| `app/Models/User.php` | `portalScopeUserIds`, `commissionRateLabel`, `subordinates` |
| `app/Models/Barang.php` | `updateBarang`, `insertBarang`, `booted()` |
| `app/Support/MarkingPricingResolver.php` | `modalRatesFromPricingRow`, `pickRow`, `normalizeKey` |
| `app/Support/Branding.php` | `absoluteLogoPath`, `logoPath` |
| `app/Http/Controllers/BarangController.php` | `store`, `update`, `requiresApproval` flow |
| `app/Http/Controllers/DashboardController.php` | Semua view vars |
| `app/Http/Controllers/MarkingController.php` | `template()` endpoint |
| `app/Providers/AppServiceProvider.php` | Route bind marking + Gate |

---

## Yang TIDAK perlu di-upload (folder lain)

Deploy ini **hanya recovery `app/`**. Folder berikut **jangan diubah** kecuali ada masalah terpisah:
- `vendor/` — sudah ada di server
- `database/migrations/` — tidak perlu untuk recovery app
- `resources/views/` — upload terpisah hanya jika view juga rusak
- `public/build/` — frontend assets (upload terpisah jika UI rusak)
- `.env` — **JANGAN overwrite** file env production

---

## Troubleshooting

| Gejala | Penyebab | Solusi |
|--------|----------|--------|
| `Call to undefined method Marking::visibleToUser()` | Upload partial/stub | Upload ulang **seluruh** `app/Models/Marking.php` + folder Models |
| `modalRatesFromPricingRow()` undefined | Resolver stub | Upload `app/Support/MarkingPricingResolver.php` |
| Logo PDF kosong | Branding stub | Upload `app/Support/Branding.php` |
| Barang edit 500 | BarangController stub | Upload `app/Http/Controllers/BarangController.php` |
| Masih 500 setelah upload | Cache lama | Jalankan artisan clear cache (langkah 6) |

---

## Confidence level

**Tinggi (85–90%)** untuk recovery backend PHP:
- ✅ `verify-deploy.php` PASS
- ✅ Syntax check 168 file PASS
- ✅ Method yang sebelumnya missing sudah ditambahkan
- ✅ Debug artifacts dihapus
- ⚠️ Unit test DB tidak bisa dijalankan lokal (sqlite driver missing) — jalankan di server jika perlu: `php artisan test`
- ⚠️ SSH refused — verifikasi penuh harus via browser + cPanel terminal

**Upload wajib:** folder `app/` **lengkap** (168 file PHP) via FileZilla.
