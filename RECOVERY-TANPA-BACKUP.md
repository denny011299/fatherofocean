# Pemulihan Production — Tanpa Backup Manual

> **Untuk Anda yang tidak punya backup sendiri:** ini bukan salah Anda.
> Situs rusak karena **kombinasi** deploy file demi file (disarankan agent) + workspace laptop yang **tidak lengkap** + **tidak ada Git**. Kita cari jalan pulih **tanpa** mengandalkan backup zip di laptop.

**Production:** `https://oceanlink.my.id`  
**Path server:** `/home/ocew9596/public_html`  
**FTP:** `ftp.oceanlink.my.id` — user `admin@oceanlink.my.id` (sudah tersimpan di FileZilla: **Ocean - Tanaya**)

---

## Siapa Bertanggung Jawab? (Jujur, Tanpa Menyalahkan Anda)

| Faktor | Keterangan |
|--------|------------|
| **Agent AI** | Beberapa sesi menyarankan upload **file per file** via FileZilla. Itu berisiko tinggi untuk Laravel — class saling bergantung. |
| **Workspace laptop** | Folder `c:\Pelayaran` **tidak punya `.git`**, isinya **parsial/stub** setelah overwrite malam 20 Juni. Bukan salinan production lengkap. |
| **Anda** | Deploy lewat FTP sesuai instruksi — **wajar** jika belum setup Git/backup sendiri. Hosting shared sering punya backup otomatis meski Anda tidak pernah klik "Backup". |

**Kesimpulan:** fokus ke **perbaikan**, bukan cari siapa salah.

---

## Opsi Pemulihan — Urutan Prioritas

### 🥇 Opsi 1 — Backup otomatis hosting (JetBackup / cPanel)

**Ini BUKAN backup manual Anda** — ini backup **pihak hosting** yang sering jalan otomatis harian.

Anda **tidak perlu pernah** setup backup sendiri; provider tetap bisa punya snapshot.

#### Cara cek (5 menit)

1. Login cPanel akun **`ocew9596`**
   - URL: `https://oceanlink.my.id:2083` atau link dari email hosting
2. Cari salah satu menu:
   - **JetBackup 5** ← paling umum
   - **Backup Wizard**
   - **Backup** → Restore
3. Buka tab **Restore & Download** atau **Restore**
4. Pilih tipe: **Home Directory** atau **Full Account**
5. Cari tanggal:
   - **Prioritas 1:** **19 Juni 2026** (malam)
   - **Prioritas 2:** **20 Juni 2026** sebelum ~00:00 WIB
   - **Jangan** restore backup setelah deploy rusak (00:30 WIB ke atas)

#### Restore aman

- Centang **`public_html`** saja (file aplikasi)
- **Jangan** restore database dulu kecuali data DB ikut hilang
- Setelah restore:
  ```bash
  cd /home/ocew9596/public_html
  rm -f public/hot
  php artisan optimize:clear
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  ```

#### Jika menu backup tidak kelihatan

Kirim tiket ke support hosting (copy-paste):

> Halo, akun cPanel **ocew9596** (domain oceanlink.my.id).
> Mohon cek apakah ada **backup otomatis / JetBackup** untuk restore folder **public_html** ke tanggal **19 Juni 2026** atau **20 Juni pagi sebelum 00:00 WIB**.
> Situs Laravel rusak karena upload file tidak lengkap.
> Path: `/home/ocew9596/public_html`

**Probabilitas sukses:** tinggi — ini jalur tercepat jika hosting punya snapshot.

---

### 🥈 Opsi 2 — Git di server production

Workspace laptop **tidak punya Git**, tapi server **mungkin punya**.

Via cPanel Terminal atau SSH:

```bash
cd /home/ocew9596/public_html
git status
git log --oneline -20
```

Jika ada repo:

```bash
git log --oneline --since="2026-06-19" --until="2026-06-20 08:00"
git reset --hard <HASH_COMMIT_STABIL>
rm -f public/hot
php artisan optimize:clear && php artisan config:cache
```

**Probabilitas sukses:** sedang — tergantung apakah Git pernah di-setup di server.

---

### 🥉 Opsi 3 — Snapshot di File Manager server

Tanpa backup formal, cPanel kadang punya:

- Folder `backup/`, `.trash/`, `public_html_backup_*`
- File `.tar.gz` / `.zip` dengan tanggal lama

**Langkah:** cPanel → File Manager → `/home/ocew9596/` → cari arsip sebelum 20 Juni.

**Probabilitas sukses:** rendah–sedang — tergantung kebiasaan hosting.

---

### Opsi 4 — Rekonstruksi dari Cursor Local History + deploy **seluruh folder sekaligus**

**Feasible — sudah diverifikasi 20 Juni 2026.**

#### Apa yang ditemukan di laptop

| Sumber | Hasil |
|--------|-------|
| Git di `c:\Pelayaran` | **Tidak ada** |
| GitHub/GitLab remote | **Tidak ada** referensi repo Pelayaran |
| `ship.okejob.id` | Domain/hosting **lama** — bukan production sekarang |
| Zip lokal | Hanya `vendor.zip`, `node_modules.zip` (Mei 2026) — **bukan full app** |
| `C:\Cahaya` (FileZilla okejob) | Proyek **ERP berbeda** (145 file) — **bukan** Ocean Link |
| FileZilla `sitemanager.xml` | Ada koneksi **Ocean - Tanaya** → `ftp.oceanlink.my.id` ✓ |
| FileZilla `queue.sqlite3` | Antrian transfer lama — **bukan** mirror backup file |
| **Cursor Local History** | Ada versi **lengkap** file yang sempat ter-stub |

#### File yang sudah dipulihkan ke workspace lokal

| File | Sebelum | Sesudah (dari history) |
|------|---------|------------------------|
| `app/Support/TerimaBarangPdfData.php` | ~156 baris (stub) | **~1030 baris** (lengkap) |
| `app/Models/Marking.php` | ~485 baris (potong) | **~727 baris** (lengkap) |
| `app/Support/Branding.php` | tanpa `absoluteLogoPath()` | **ditambah** method yang hilang |
| `app/Models/User.php` | tanpa `commissionRateOverride()` | **lengkap** — komisi, supervisor capital gain, scopes |

#### Error production (20 Juni — beruntun karena upload parsial)

| # | Error | File pemicu | Method hilang di `User.php` |
|---|-------|-------------|----------------------------|
| 1 | `commissionRateOverride()` | `SalesCommissionSettings.php:29` | `commissionRateOverride()` |
| 2 | `earnsCapitalGainOnly()` | `SalesRevenueCalculator.php:200` | `earnsCapitalGainOnly()` |

**Penyebab:** upload **file per file** — class pemanggil sudah versi baru, `User.php` di server masih stub.

**Perbaikan lokal (`User.php` sekarang punya SEMUA method custom):**

| Method / scope | Dipakai oleh |
|----------------|--------------|
| `earnsCapitalGainOnly()` | `SalesRevenueCalculator` — supervisor dapat capital gain, bukan komisi % |
| `commissionRateOverride()` | `SalesCommissionSettings`, `commissionRateLabel()` |
| `commissionRateLabel()` | UI manajemen user (badge komisi sales) |
| `scopeMarketingStaff()` | `SalesRevenueController`, tests — sales + supervisor |
| `scopeListedInUserManagement()` | `UserController::data()` |
| `portalScopeUserIds()` | `Marking` — scope tim supervisor |
| `subordinates()` / `supervisingUsers()` | `Marking`, omset, notifikasi |

**Kolom `$fillable` / `casts`:** `sales_commission_rate_override`, `is_marking_portal`, `marking_code` (migrasi sudah ada — **bukan** kolom DB baru).

### ⚠️ DEPLOY WAJIB: SELURUH folder `app/` — SEKALI JALAN

**JANGAN upload hanya `User.php`.** Setiap perbaikan parsial hanya mengungkap method berikutnya yang hilang di server.

```
Local:  c:\Pelayaran\app\
Remote: /home/ocew9596/public_html/app/
```

- Drag **folder `app/`** ke FileZilla → overwrite all (~179 file PHP)
- Mode: overwrite jika ukuran berbeda / source lebih baru
- Setelah upload: `rm -f public/hot && php artisan optimize:clear && php artisan config:cache`

#### Hasil validator

```bash
php tools/verify-deploy.php
```

- **7 error** → setelah rekonstruksi: **3 "error" tersisa**
- 3 sisanya = **false positive** — validator cek `visibleToUser()` / `fullyPaid()` sebagai method biasa, padahal di Laravel ini **Eloquent scope** (`scopeVisibleToUser`, `scopeFullyPaid`) yang **sudah ada** dan jalan normal.

**Artinya:** workspace lokal **cukup layak** untuk deploy bundle penuh — **bukan** upload file per file lagi.

#### Sumber history Cursor (jika perlu manual)

| File | Path history |
|------|----------------|
| `TerimaBarangPdfData.php` | `%AppData%\Cursor\User\History\62b8be8e\UDbU.php` |
| `Marking.php` | `%AppData%\Cursor\User\History\78515f2f\26Kg.php` |

Buka di Cursor: klik kanan file → **Local History** → pilih versi sebelum 20 Juni 00:00.

**Probabilitas sukses:** sedang–tinggi — asalkan deploy **bundle penuh**, bukan sepotong-sepotong.

---

### Opsi 5 — FileZilla / salinan lokal lain

| Dicek | Hasil |
|-------|-------|
| Site Manager | Ada **Ocean - Tanaya** (oceanlink) ✓ |
| Local directory mirror oceanlink | **Tidak ada** folder mirror khusus oceanlink |
| `C:\Cahaya` | Proyek lain (okejob), bukan Pelayaran |
| `C:\ship` | **Tidak ada** |
| Transfer history lengkap | FileZilla **tidak** menyimpan mirror otomatis — hanya antrian |

**Probabilitas sukses:** rendah untuk full restore — tapi koneksi FTP sudah siap dipakai untuk deploy bundle.

---

### Opsi 6 — Windows File History / Shadow Copy

Butuh hak **Administrator**. Jika pernah aktif, bisa ada snapshot `c:\Pelayaran` sebelum 20 Juni.

**Probabilitas sukses:** rendah (sering tidak aktif di PC dev).

---

## ⚠️ JANGAN LAKukan Ini

1. **Jangan** upload file satu per satu lagi — itu penyebab production campur versi lama/baru/stub.
2. **Jangan** overwrite `.env` production dengan `.env` laptop.
3. **Jangan** upload folder `vendor/` kecuali `composer.json` berubah dan Anda jalankan `composer install` di server.
4. **Jangan** upload `public/hot` — hapus di server: `rm -f public/hot`.
5. **Jangan** restore database kecuali data benar-benar hilang.

---

## Plan Deploy: Upload Seluruh Bundle Sekaligus (Opsi 4)

### Persiapan lokal (sudah dilakukan sebagian)

```bash
cd c:\Pelayaran
php tools/verify-deploy.php
# 3 "error" scope = aman diabaikan; yang penting tidak ada [ref] atau [syntax]
```

Pastikan assets frontend ada:

```bash
# Jika belum: npm run build
# Pastikan ada: public/build/manifest.json
```

### Koneksi FileZilla

| Field | Nilai |
|-------|-------|
| Host | `ftp.oceanlink.my.id` |
| Port | `21` |
| User | `admin@oceanlink.my.id` |
| Remote dir | `/public_html` |

(Gunakan entry **Ocean - Tanaya** di Site Manager.)

### Upload — **seluruh folder**, bukan file tunggal

**Local:** `c:\Pelayaran\` → **Remote:** `/home/ocew9596/public_html/`

| # | Upload (drag folder) | Remote path | Catatan |
|---|----------------------|-------------|---------|
| 1 | `app/` | `public_html/app/` | **SELURUH folder** — **179 file PHP** (drag folder, overwrite all) |
| 2 | `bootstrap/app.php` | `public_html/bootstrap/app.php` | |
| 3 | `bootstrap/providers.php` | `public_html/bootstrap/providers.php` | |
| 4 | `public/build/` | `public_html/public/build/` | CSS/JS — 3 file + manifest |
| 5 | `routes/` | `public_html/routes/` | jika belum sync |
| 6 | `resources/views/` | `public_html/resources/views/` | jika belum sync |
| 7 | `lang/` | `public_html/lang/` | i18n wajib |
| 8 | `config/` | `public_html/config/` | hati-hati jangan timpa custom server |
| 9 | `tools/verify-deploy.php` | `public_html/tools/verify-deploy.php` | untuk cek di server |

**Jangan upload:** `.env`, `vendor/`, `node_modules/`, `public/hot`, `.git`

**Mode FileZilla:** Overwrite jika ukuran berbeda / source lebih baru.

### Setelah upload — Terminal cPanel

```bash
cd /home/ocew9596/public_html

# Wajib — CSS hilang kalau file ini ada
rm -f public/hot

# Bersihkan cache
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Validasi (opsional)
php tools/verify-deploy.php
```

### Smoke test

| # | URL / aksi | Harus |
|---|------------|-------|
| 1 | `/login` | CSS Bootstrap normal (bukan Times New Roman) |
| 2 | Login super_admin | Tidak 419/500 |
| 3 | `/barang` | Daftar tampil |
| 4 | `/barang/{id}/edit` | Form edit terbuka |
| 5 | `/export/omset` | Tidak error `Locales::name()` |
| 6 | PDF terima barang | Tidak error class/method |
| 7 | `/up` | Health check OK |

---

## Rekomendasi Praktis

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Coba JetBackup/hosting DULU (15 menit, gratis)          │
│    → Jika ada snapshot 19 Juni: restore public_html        │
│                                                             │
│ 2. Jika tidak ada backup hosting:                           │
│    → Deploy BUNDLE penuh (tabel di atas)                   │
│    → Workspace sudah direkonstruksi dari Cursor History     │
│                                                             │
│ 3. Setelah stabil:                                          │
│    → Init Git + push ke GitHub/GitLab                       │
│    → Deploy hanya via zip/git pull, never file per file     │
└─────────────────────────────────────────────────────────────┘
```

---

## Referensi Teknis

| Dokumen | Isi |
|---------|-----|
| `tools/DEPLOY-MANIFEST.md` | Bundle dependensi antar file |
| `scripts/deploy-production.sh` | Checklist post-deploy (Linux) |
| `RESTORE-PROVIDERS.md` | File provider Laravel yang wajib ada |
| `.env.example` | Contoh config production (oceanlink.my.id) |

---

## Catatan tentang `ship.okejob.id`

Domain/hosting **lama**. Production saat ini = **oceanlink.my.id** di `/home/ocew9596/public_html`.  
Script `deploy-production.sh` masih menyebut ship.okejob.id — itu komentar lama, bukan target deploy sekarang.

---

*Dibuat: 20 Juni 2026 — investigasi recovery tanpa asumsi backup manual user.*
