# RESTORE — Versi Stabil Pagi Tadi (oceanlink.my.id)

> **PENTING:** Jangan upload file apapun dari laptop (`c:\Pelayaran`) ke production.
> Workspace lokal **tidak punya versi stabil pagi tadi**. Restore harus dari **backup hosting**.

---

## Hasil Investigasi Lengkap (20 Juni 2026)

| Sumber | Hasil |
|--------|-------|
| Git di `c:\Pelayaran` | **TIDAK ADA** — folder `.git` tidak ditemukan |
| Git di folder parent / `ship.okejob` | **Tidak ditemukan** |
| Commit hash versi pagi | **Tidak bisa ditentukan** dari laptop |
| Backup zip lokal | Hanya `vendor.zip`, `vendor (2).zip`, `vendor (3).zip`, `node_modules.zip` (**Mei 2025–2026**) — **bukan full app** |
| Folder backup lokal | **Tidak ditemukan** di Desktop/Downloads/Documents |
| FileZilla local mirror | **Tidak ditemukan** — tidak ada `sitemanager.xml` / konfigurasi FTP tersimpan |
| Windows File History / Shadow Copy | **Tidak bisa diakses** (butuh admin) |
| Cursor Local History | Ada **sebagian**, tapi versi terakhir sebelum crash = **14–16 Juni**, **bukan pagi 20 Juni** |
| Seluruh folder `app/` lokal | **179 file** di-overwrite **20 Juni 2026 00:00–01:30 WIB** |
| Validator deploy lokal | **GAGAL** — 7 error (stub/parsial) |

### Kesimpulan

**Tidak ada salinan lengkap "versi stabil pagi tadi" di laptop.**

Versi stabil pagi tadi = **production server sebelum deploy rusak malam ini**.
Satu-satunya cara restore penuh: **backup hosting (cPanel/JetBackup)**.

---

## Apa Artinya "Versi Stabil Pagi Tadi"?

| Waktu | Status |
|-------|--------|
| **Sebelum 20 Juni 2026 ~00:00 WIB** | Production stabil, semua fitur jalan |
| **20 Juni 00:00 – 01:30 WIB** | Agent deploy file parsial dari laptop → production rusak |
| **Gejala error** | `BarangController::edit()` tidak ada, CSS hilang, `Locales::name()` tidak ada, class not found, MySQL too many connections |

**Target restore:** snapshot backup **19 Juni 2026 malam** atau **20 Juni 2026 sebelum jam 00:00 WIB**.

---

## LANGKAH 1 — Restore dari cPanel JetBackup (JALUR UTAMA)

### Persiapan

1. **STOP** upload FileZilla dari laptop — jangan sentuh production dulu
2. Login cPanel akun **`ocew9596`**
3. URL cPanel biasanya: `https://oceanlink.my.id:2083` atau dari email hosting

### A. JetBackup 5 (paling umum di shared hosting)

1. Di cPanel, cari dan klik **JetBackup 5**
2. Pilih tab **Restore & Download**
3. Pilih tipe backup: **Home Directory** (atau **Full Account** jika tidak ada opsi folder)
4. Cari backup dengan tanggal:
   - **Prioritas 1:** `19 Juni 2026` — backup malam (sebelum deploy rusak)
   - **Prioritas 2:** `20 Juni 2026` — backup pagi dini hari **sebelum 00:00 WIB**
   - **JANGAN pilih:** backup setelah 20 Juni 00:30 WIB (sudah rusak)
5. Klik backup yang sesuai → **Restore**
6. Pilih item restore:
   - Centang **`public_html`** saja (atau seluruh home jika Laravel di root `public_html`)
   - **JANGAN centang** restore database dulu (kecuali DB juga rusak — lihat bagian Database di bawah)
7. Konfirmasi restore → tunggu **5–15 menit**
8. Cek email notifikasi JetBackup jika ada

### B. Backup Wizard (jika tidak ada JetBackup)

1. cPanel → **Backup** atau **Backup Wizard**
2. Di bagian **Restore a Home Directory Backup**:
   - Upload file `.tar.gz` backup lama jika punya, ATAU
   - Pilih dari daftar backup otomatis hosting
3. Pilih tanggal **19 Juni 2026**
4. Restore ke `/home/ocew9596/`

### C. File Manager — cek snapshot manual

1. cPanel → **File Manager**
2. Masuk ke `/home/ocew9596/`
3. Cari folder/file seperti:
   - `backup/`
   - `public_html_backup_*`
   - `.trash/` (file zip lama)
   - `*.tar.gz` atau `*.zip` dengan tanggal 19 Juni
4. Jika ada zip backup pagi tadi:
   - Rename folder sekarang: `public_html` → `public_html_RUSAK_20260620`
   - Extract zip backup → jadi `public_html` baru

### D. Hubungi support hosting (jika tidak ada menu backup)

Kirim tiket/chat dengan teks:

> Halo, akun cPanel **ocew9596** (domain oceanlink.my.id).
> Mohon restore folder **public_html** ke snapshot backup **19 Juni 2026** atau **20 Juni 2026 pagi sebelum jam 00:00 WIB**.
> Situs Laravel rusak karena upload file parsial malam ini.
> Path: `/home/ocew9596/public_html`

---

## LANGKAH 2 — Setelah Restore (Terminal cPanel / SSH)

```bash
cd /home/ocew9596/public_html

# Pastikan .env production masih ada (JANGAN di-overwrite)
ls -la .env
cat .env | grep APP_URL

# Hapus file dev Vite — penyebab CSS hilang (Times New Roman, no Bootstrap)
rm -f public/hot

# Bersihkan cache Laravel
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Pastikan `.env` production benar

**Jangan timpa `.env` dari backup lama** jika kredensial DB sudah berubah. Cek nilai ini:

```env
APP_URL=https://oceanlink.my.id
APP_ENV=production
APP_DEBUG=false
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
DB_PERSISTENT=false
```

Jika `.env` hilang saat restore, buat ulang dari backup `.env` terpisah atau hubungi hosting.

### Restart PHP (jika masih error 500)

1. cPanel → **Select PHP Version** → klik **Save** (tanpa ubah apa-apa)
2. Atau cPanel → **MultiPHP Manager** → restart PHP-FPM

---

## LANGKAH 3 — Cek Git di Server (opsional, cepat)

```bash
cd /home/ocew9596/public_html
git status
```

### Jika git ADA di server:

```bash
# Lihat commit sebelum deploy rusak
git log --oneline --since="2026-06-19" --until="2026-06-20 08:00"

# Rollback ke commit pagi tadi (ganti HASH dengan hash yang muncul)
git reset --hard <COMMIT_HASH_PAGI_TADI>

rm -f public/hot
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Jika muncul `fatal: not a git repository`:

Lewati langkah ini — **pakai cPanel backup saja** (Langkah 1).

---

## LANGKAH 4 — Verifikasi Situs Stabil

Centang semua sebelum bilang "sudah stabil":

| # | Test | Harus |
|---|------|-------|
| 1 | Buka `https://oceanlink.my.id/login` | CSS normal (Bootstrap), **bukan** Times New Roman |
| 2 | Login super_admin | Berhasil, tidak error 419/500 |
| 3 | Dashboard | Terbuka tanpa error 500 |
| 4 | `/barang` | Daftar Kirim tampil |
| 5 | `/barang/209/edit` (atau ID lain) | Form edit terbuka — **bukan** `undefined method edit()` |
| 6 | Export omset | Tidak error `Locales::name()` |
| 7 | PDF label / terima barang | Tidak error `Class not found` |
| 8 | `https://oceanlink.my.id/up` | Health check OK |

---

## Database — Restore atau Biarkan?

| Situasi | Tindakan |
|---------|----------|
| Error PHP / method not found saja | **Restore file saja**, DB **biarkan** |
| Fitur baru (Pay Out, pengajuan, dll.) sudah dipakai & data penting | **Jangan rollback DB** — kode lama bisa error karena kolom baru |
| DB ikut rusak / data hilang | Restore DB dari backup **19 Juni** (hati-hati — timpa data hari ini) |

**Rekomendasi:** restore **file saja** dulu. Test login & halaman utama. Rollback DB hanya jika benar-benar perlu.

---

## JANGAN LAKUKAN Setelah Restore

| Jangan | Alasan |
|--------|--------|
| Upload file dari `c:\Pelayaran` ke production | Workspace lokal rusak — campuran stub + file lengkap |
| Upload folder `app/` parsial | Penyebab error berantai malam ini |
| Deploy file satu per satu via FileZilla | Risiko stub/hilang method lagi |
| Jalankan script `tools/_restore_*.py` di production | Hanya untuk dev lokal |
| Restore DB + file sekaligus tanpa pikir | Bisa hilang data transaksi hari ini |
| Patch error satu per satu via agent | User minta **restore**, bukan patch lagi |

### File yang pernah jadi stub di production (JANGAN upload dari laptop)

- `app/Http/Controllers/BarangController.php`
- `app/Support/Locales.php`
- `app/Support/MarkingPricingResolver.php`
- `app/Support/ShippingLabelPdfData.php`
- `app/Support/TerimaBarangPdfData.php`
- `app/Support/OmsetInvoiceQuery.php`
- `app/Models/Marking.php`
- `app/Models/Invoice.php`

---

## Opsi Cadangan (TIDAK Direkomendasikan untuk "Pagi Tadi")

### Cursor Local History (laptop)

Ada snapshot **sebagian** di:
`C:\Users\denny\AppData\Roaming\Cursor\User\History\`

**Masalah:**
- Versi terakhir sebelum crash = **14–16 Juni 2026**, bukan pagi 20 Juni
- Banyak file (mis. `Locales.php`) **tidak punya** history sebelum crash
- Tidak bisa restore full app — hanya file per file
- Production pagi tadi punya kode **lebih baru** dari history Cursor

**Contoh** (hanya referensi, jangan pakai sebagai restore utama):

| File | History terakhir sebelum crash | Tanggal |
|------|-------------------------------|---------|
| `BarangController.php` | `5zQF.php` | 14 Juni 2026 |
| `Marking.php` | `26Kg.php` | 14 Juni 2026 |
| `TerimaBarangPdfData.php` | `UDbU.php` | 14 Juni 2026 |
| `Locales.php` | **Tidak ada** | — |

### vendor.zip (Mei 2026)

Hanya berisi folder `vendor/` — **bukan** source code app. Tidak membantu restore versi stabil.

### ship.okejob.id

Domain/hosting lama (transcript lama). Production saat ini = **oceanlink.my.id** di `/home/ocew9596/public_html`. Jangan campur.

---

## Checklist Cepat — 5 Menit

```
[ ] STOP upload FileZilla dari laptop
[ ] Login cPanel ocew9596
[ ] JetBackup → restore public_html → tanggal 19 Juni / pagi 20 Juni (sebelum 00:00)
[ ] Jangan timpa .env dan storage/app/public/
[ ] cd /home/ocew9596/public_html
[ ] rm -f public/hot
[ ] php artisan optimize:clear && php artisan config:cache
[ ] Test login + dashboard + /barang/{id}/edit
[ ] Kabari developer — JANGAN patch satu per satu lagi
```

---

## Setelah Stabil (NANTI, bukan sekarang)

1. Setup git di project:
   ```bash
   git init
   git add .
   git commit -m "Baseline stabil setelah rollback cPanel"
   ```
2. Sebelum deploy berikutnya:
   ```bash
   php tools/verify-deploy.php
   npm run build
   rm -f public/hot
   ```
3. Upload **seluruh bundle** sekaligus — lihat `tools/DEPLOY-MANIFEST.md`
4. Di server: `bash scripts/deploy-production.sh`

---

## Ringkasan Satu Kalimat

**Restore `public_html` dari backup cPanel tanggal 19 Juni 2026 — jangan upload apapun dari laptop.**

---

*Dibuat setelah investigasi lengkap — 20 Juni 2026.*
*Lihat juga: `ROLLBACK.md` (panduan serupa)*
