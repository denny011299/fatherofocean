# ROLLBACK — Kembalikan oceanlink.my.id ke Versi Stabil

> **URGENT:** Jangan upload file apapun dari laptop (`c:\Pelayaran`) ke production dulu.
> Workspace lokal saat ini **rusak** (partial stub + restore transcript gagal).
> Satu-satunya jalan aman: **restore backup hosting**.

---

## Ringkasan Investigasi (20 Juni 2026)

| Item | Status |
|------|--------|
| Git di `c:\Pelayaran` | **TIDAK ADA** (`.git` tidak ditemukan) |
| Commit hash versi pagi | **Tidak bisa ditentukan** — tidak ada history git lokal |
| Backup zip lokal | Hanya `vendor.zip` / `node_modules.zip` (Mei 2026) — **bukan full app** |
| Backup folder lokal | **Tidak ditemukan** |
| Deploy script | `scripts/deploy-production.sh` (checklist, bukan rollback) |
| Production path | `/home/ocew9596/public_html` |
| Domain | `https://oceanlink.my.id` |
| Metode deploy biasa | FileZilla / cPanel File Manager (upload manual) |

### Apa artinya "versi stabil pagi tadi"?

Versi stabil = **production sebelum upload file parsial malam ini (20 Juni ~00:00 WIB ke atas)**.

Gejala yang muncul setelah deploy rusak:
- `Call to undefined method BarangController::edit()`
- `Locales::name()` tidak ada
- `MarkingPricingResolver::normalizeKey()` tidak ada
- `Class "App\Support\ShippingLabelPdfData" not found`
- CSS hilang (file `public/hot` / asset Vite)
- Error SQL / MySQL too many connections

**Target restore:** backup **19 Juni 2026** (malam) atau **20 Juni 2026 pagi** (sebelum deploy agent).

---

## LANGKAH 1 — Restore dari cPanel (PALING AMAN)

### A. JetBackup / Backup Manager (jika ada)

1. Login **cPanel** akun hosting `ocew9596`
2. Buka **JetBackup 5** atau **Backup Wizard** atau **Backup**
3. Pilih restore **Home Directory** atau folder **`public_html`**
4. Pilih tanggal: **19 Juni 2026** atau **20 Juni pagi** (sebelum error)
5. Restore **hanya file** — jangan restore database kecuali Anda yakin DB juga rusak
6. Tunggu selesai (5–15 menit)

### B. cPanel File Manager — snapshot harian

1. cPanel → **File Manager**
2. Masuk ke `/home/ocew9596/`
3. Cek apakah ada folder seperti:
   - `backup/`
   - `.trash/` dengan zip lama
   - `public_html_backup_*`
4. Jika ada zip backup pagi tadi → extract ke `public_html` (backup dulu folder sekarang)

### C. Minta hosting restore

Jika tidak ada menu backup di cPanel:
- Hubungi support hosting
- Minta: *"Restore public_html ke snapshot 19 Juni 2026 atau pagi 20 Juni 2026"*

---

## LANGKAH 2 — Perintah Setelah Restore (Terminal cPanel / SSH)

```bash
cd /home/ocew9596/public_html

# Jangan timpa .env — pastikan file .env production masih ada & benar
ls -la .env

# Hapus file dev Vite (penyebab CSS hilang)
rm -f public/hot

# Bersihkan cache Laravel
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Cek `.env` production (jangan di-overwrite restore)

Pastikan nilai ini ada (sesuaikan jika perlu):

```env
APP_URL=https://oceanlink.my.id
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
DB_PERSISTENT=false
```

Lalu:

```bash
php artisan config:clear
php artisan config:cache
```

### Restart PHP (jika masih error)

cPanel → **Select PHP Version** → **Save** (tanpa ubah apa-apa)  
atau **MultiPHP Manager** → restart PHP-FPM

---

## LANGKAH 3 — Jika Server Punya Git (cek dulu)

```bash
cd /home/ocew9596/public_html
git status
```

**Jika `git` ada:**

```bash
git log --oneline --since="2026-06-19" --until="2026-06-20 08:00"
```

Cari commit **terakhir sebelum deploy rusak**, lalu:

```bash
git reset --hard <COMMIT_HASH_PAGI_TADI>
rm -f public/hot
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Jika `fatal: not a git repository`:** lewati langkah ini, pakai cPanel backup saja.

---

## LANGKAH 4 — FileZilla (hanya jika punya backup lokal LENGKAP)

**Jangan upload `c:\Pelayaran` sekarang** — workspace lokal gagal validasi deploy.

FileZilla aman **hanya jika** Anda punya:
- Zip/folder backup **full project** dari sebelum malam ini, ATAU
- Copy `public_html` lama di komputer lain

### Prosedur upload backup lama:

1. Di server, rename folder rusak:
   ```
   public_html → public_html_RUSAK_20260620
   ```
2. Upload backup lama sebagai `public_html` baru
3. **Jangan timpa** dari backup lama:
   - `.env` (pakai .env production yang sekarang)
   - `storage/app/public/` (foto barang, logo — data user)
4. Jalankan perintah Langkah 2

---

## JANGAN DEPLOY dari Workspace Cursor Saat Ini

Workspace `c:\Pelayaran` **tidak aman** untuk production.

Validator deploy gagal (`php tools/verify-deploy.php`):

```
FAIL — error:
  • TerimaBarangPdfData — method hilang (stub ~127 baris)
  • Branding::absoluteLogoPath() hilang
  • dan method lain tidak lengkap
```

Seluruh folder `app/` di-overwrite **20 Juni 00:48** oleh script restore transcript — hasilnya campuran file lengkap + stub.

### File/folder yang JANGAN di-upload dari laptop sekarang

| Jangan upload | Alasan |
|---------------|--------|
| **`app/` (seluruh folder)** | Di-overwrite malam ini; ada stub parsial |
| `app/Http/Controllers/BarangController.php` | Pernah di-deploy sebagai stub ~350 baris |
| `app/Support/Locales.php` | Pernah stub di production |
| `app/Support/MarkingPricingResolver.php` | Pernah stub |
| `app/Support/ShippingLabelPdfData.php` | Namespace/path sering salah |
| `app/Support/TerimaBarangPdfData.php` | Stub — method PDF hilang |
| `app/Support/OmsetInvoiceQuery.php` | SQL binding error jika parsial |
| `app/Http/Controllers/ExportController.php` | Harus bundle dengan Omset* |
| `app/Models/Marking.php` | Harus full model, bukan stub |
| `app/Models/Invoice.php` | Harus bundle dengan omset export |
| `tools/` (script restore) | Hanya untuk dev, bukan production |

### File yang relatif aman (tidak diubah 20 Juni) — tapi tetap jangan deploy parsial

- `resources/` (views, css, js blade)
- `public/Custom_js/`
- `routes/web.php`
- `lang/`

**Aturan emas:** setelah rollback stabil, deploy fitur baru **hanya sekaligus** (full bundle), bukan file per file.

---

## Database — Rollback atau Tidak?

| Situasi | Tindakan |
|---------|----------|
| Hanya error PHP / method not found | **Restore file saja**, DB biarkan |
| Sudah jalankan SQL fitur baru & ingin fitur itu hilang | Rollback DB dari backup hosting (hati-hati!) |
| Fitur baru (company_payouts, barang_edit_requests, dll.) sudah jalan | **Jangan rollback DB** — kode lama mungkin error karena kolom baru |

Setelah restore file ke versi lama, situs harus bisa login & buka halaman utama dulu. Fitur yang butuh migration baru **mati sementara** — itu normal dan lebih aman daripada patch terus.

---

## Smoke Test Setelah Rollback

1. Buka `https://oceanlink.my.id/login` — CSS harus normal (Bootstrap, bukan Times New Roman)
2. Login super_admin
3. Dashboard terbuka tanpa error 500
4. `/barang` — Daftar Kirim tampil
5. `/barang/{id}/edit` — form edit terbuka (bukan `undefined method edit()`)
6. Export omset — tidak error `Locales::name()`
7. PDF label / terima barang — tidak error class not found

---

## Setelah Stabil — Deploy Ulang yang Benar (NANTI)

1. Setup git di project (disarankan):
   ```bash
   git init
   git add .
   git commit -m "Baseline stabil setelah rollback"
   ```
2. Sebelum upload ke server:
   ```bash
   php tools/verify-deploy.php
   npm run build
   rm -f public/hot
   ```
3. Upload **seluruh bundle** sesuai `tools/DEPLOY-MANIFEST.md`
4. Di server: `bash scripts/deploy-production.sh`

---

## Kontak Cepat — Checklist 5 Menit

- [ ] Stop upload FileZilla dari laptop
- [ ] cPanel → restore `public_html` backup **19 Juni / pagi 20 Juni**
- [ ] `rm -f public/hot`
- [ ] `php artisan optimize:clear && php artisan config:cache`
- [ ] Test login + dashboard + `/barang/{id}/edit`
- [ ] Kabari developer — **jangan patch satu per satu lagi**

---

*Dibuat otomatis setelah investigasi rollback — 20 Juni 2026.*
