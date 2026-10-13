# Restore Production via FileZilla (oceanlink.my.id)

Audit mesin Windows **20 Juni 2026** — mencari salinan lokal production **sebelum** deploy rusak malam ini.

---

## Ringkasan eksekutif

| Pertanyaan | Jawaban |
|------------|---------|
| Ada folder lokal lengkap versi stabil 19 Juni? | **Tidak ditemukan** path terpisah di PC ini |
| Folder apa yang dipakai FileZilla sebagai mirror lokal? | **`C:\Pelayaran\`** |
| Apakah `C:\Pelayaran` aman di-upload sekarang? | **Tidak** — `app\` sudah di-overwrite **20 Juni 2026 ~01:30** |
| Backup di FileZilla (panel kanan)? | **Mungkin** — cek folder **`backup/`** dan **`public_html_backup*`** di **remote** akun hosting (bukan cache FileZilla) |

**Jalur terbaik:** unduh snapshot dari **folder backup di server** (atau JetBackup cPanel), lalu upload ke `public_html` — **bukan** dari isi `C:\Pelayaran` saat ini.

---

## Temuan pencarian di komputer ini

### 1. Konfigurasi FileZilla

| Path | Isi relevan |
|------|-------------|
| `C:\Users\denny\AppData\Roaming\FileZilla\filezilla.xml` | Tab aktif: `ftp.oceanlink.my.id` → **LocalPath `C:\Pelayaran\`**, RemotePath root (`1 0`) |
| `C:\Users\denny\AppData\Roaming\FileZilla\sitemanager.xml` | Situs **Ocean - Tanaya** (`ftp.oceanlink.my.id`, user `admin@oceanlink.my.id`) — **tanpa** `LocalDir` tetap |
| `C:\Users\denny\AppData\Roaming\FileZilla\queue.sqlite3` | Tabel ada, **riwayat transfer kosong** (tidak menyimpan upload 19 Juni) |
| `recents.xml` | **Tidak ada** di folder Roaming FileZilla |
| `C:\Users\denny\AppData\Local\FileZilla\` | Hanya ikon UI, **bukan** salinan kode |

Situs lain di Site Manager (tidak untuk oceanlink): CRM, Glossun, okejob (`LocalDir` **`C:\Cahaya`**), VPS INDORAYA (`LocalDir` **`C:\indoraya`**).

### 2. Folder yang dicari (nama `public_html`, `oceanlink`, `Pelayaran`, `backup`, `ocew9596`)

| Path | Status |
|------|--------|
| `C:\Pelayaran\` | **Ada** — mirror FileZilla; **sudah campuran / rusak** malam 20 Juni |
| `C:\ocean\` | **Ada** — proyek Laravel lama (**8 Juni 2026**), **bukan** versi stabil 19 Juni |
| `C:\Users\denny\public_html`, `oceanlink`, `backup`, `ocew9596` | **Tidak ada** |
| `Downloads` / `Desktop` / `Documents` — arsip `*backup*`, `public_html*`, `.tar.gz` terkait pelayaran | **Tidak ditemukan** (pencarian 19–20 Juni 2026) |
| `public_html` di drive C:/D: (depth terbatas) | **Tidak ditemukan** |

### 3. Salinan `AppServiceProvider.php` lain di disk

| File | Ukuran | Terakhir diubah | Catatan |
|------|--------|-----------------|---------|
| **`C:\Pelayaran\app\Providers\AppServiceProvider.php`** | **1372** byte | **20/06/2026 01:30** | Versi **workspace saat ini** (setelah perubahan malam ini) — **jangan anggap snapshot 19 Juni** |
| `C:\ocean\app\Providers\AppServiceProvider.php` | 361 byte | 14/03/2026 | Template Laravel lama |
| `C:\Users\denny\.gemini\antigravity*\scratch\ocean\...` | 361 byte | 14/03/2026 | Bukan production Juni |

Tidak ada salinan lokal `AppServiceProvider.php` dengan tanggal **19 Juni 2026** yang terpisah dari `C:\Pelayaran`.

### 4. Arsip zip di `C:\Pelayaran` (bukan full app)

- `vendor.zip`, `vendor (2).zip`, `vendor (3).zip` — **Mei 2026**, hanya dependencies Composer
- `node_modules.zip` — **Mei 2026**

---

## Bukti: `C:\Pelayaran` bukan snapshot 19 Juni

- Folder **`app\`** dan banyak file inti: timestamp **20 Juni 2026 ~01:30**
- Hanya **sebagian kecil** file di luar `app\` yang masih bertanggal 19 Juni (mis. sebagian `resources\`, `lang\`, `tests\`, `public\`)
- **Upload ulang seluruh `C:\Pelayaran`** berisiko menimpa server dengan kode yang **sama tidak lengkapnya** dengan malam ini

---

## Opsi A — Backup ada di SERVER (panel kanan FileZilla)

FileZilla **tidak** menyimpan backup otomatis di laptop. Yang user maksud “ada di FileZilla” sering = **folder di sisi remote** saat terhubung FTP.

### Langkah: cari dan unduh backup dari server

1. Buka **FileZilla** → **Site Manager** → **Ocean - Tanaya** → **Connect**.
2. **Panel kanan (Remote):** setelah login, naik ke **home akun** (biasanya sudah di `/` atau `/home/ocew9596`).
3. Cari folder/file berikut (urutan prioritas):

   | Nama di remote | Apa itu |
   |----------------|---------|
   | **`public_html`** | Production **sekarang** (rusak) — jangan timpa sebelum backup |
   | **`backup/`** | Folder backup manual hosting / script |
   | **`public_html_backup_*`** | Salinan folder lama |
   | **`*.tar.gz` / `*.zip`** dengan tanggal **18–19 Juni 2026** | Arsip full site |
   | **`.trash/`** | Kadang ada zip lama |

4. Jika menemukan arsip atau folder backup **sebelum 20 Juni 00:30 WIB**:
   - **Download** ke folder lokal **baru**, mis. `C:\Restore-oceanlink-19jun\` — **jangan** ke `C:\Pelayaran\`.
   - Jika file `.tar.gz`: extract di PC (7-Zip), pastikan isinya ada `artisan`, `app\`, `routes\`, `config\`.

### Langkah: restore ke production via FileZilla

1. **Backup folder rusak di server** (panel kanan):
   - Klik kanan **`public_html`** → **Rename** → `public_html_RUSAK_20260620`
2. Buat **`public_html`** kosong (klik kanan → Create directory), atau rename folder hasil extract backup menjadi `public_html`.
3. **Upload** isi snapshot (seluruh isi Laravel, bukan hanya `app/`) ke **`public_html`**.
4. **Jangan upload / timpa** dari PC:
   - **`.env`** di server (kredensial DB production)
   - **`storage/app/public`** (upload user) — kecuali yakin snapshot masih cocok
5. Di **Terminal cPanel / SSH**:

```bash
cd /home/ocew9596/public_html
rm -f public/hot
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

6. Tes: login, dashboard, `/barang/{id}/edit`, CSS Bootstrap normal.

### Jika di remote hanya ada folder `backup/` (bukan zip)

1. Masuk **`backup/`** di panel kanan.
2. Sort by **Date** — pilih entri **19 Juni 2026** (atau terakhir sebelum deploy rusak).
3. Download subtree **`public_html`** atau arsip di dalamnya ke `C:\Restore-oceanlink-19jun\`.
4. Lanjut langkah rename + upload di atas.

---

## Opsi B — Salinan lokal utuh ditemukan (bukan `C:\Pelayaran` sekarang)

Hanya jika Anda punya folder lain yang lengkap (`artisan`, `app\Http\Controllers`, `composer.json`, dll.) dan timestamp **19 Juni** atau lebih lama stabil.

1. Panel kiri FileZilla → buka folder snapshot itu.
2. Panel kanan → rename `public_html` → `public_html_RUSAK_20260620`.
3. Upload **semua** isi snapshot ke `public_html` baru (skip `.env` dari PC).
4. Jalankan perintah `artisan` di atas.

---

## Opsi C — Tidak ada backup di remote dan tidak ada salinan lokal

1. **JetBackup 5 / Backup Wizard** di cPanel akun **`ocew9596`** — restore **`public_html`** ke **19 Juni 2026** (sebelum 20 Juni 00:30 WIB). Detail: `RESTORE-STABLE-MORNING.md`, `PENJELASAN-KEGAGALAN.md`.
2. **Windows Previous Versions** (butuh admin): klik kanan `C:\Pelayaran` → Properties → Previous Versions → restore ke folder **baru** jika ada snapshot 19 Juni.

---

## Panel kiri FileZilla (lokal) — path yang benar

| Tujuan | Path |
|--------|------|
| Mirror yang dipakai malam ini (rusak) | `C:\Pelayaran\` |
| Unduh backup dari server | Buat folder baru, mis. **`C:\Restore-oceanlink-19jun\`** |
| Proyek lama (jangan pakai untuk restore Juni) | `C:\ocean\` |

Setelah connect oceanlink, cek baris atas **panel kiri** — harus menunjuk ke folder yang **sengaja** Anda pilih untuk upload, bukan `C:\Pelayaran` kecuali sudah diverifikasi utuh.

---

## Checklist sebelum upload

- [ ] Snapshot sumber = **server backup / JetBackup / folder lokal 19 Juni**, bukan `C:\Pelayaran` malam 20 Juni
- [ ] `public_html` lama sudah di-rename di server
- [ ] `.env` production **tidak** tertimpa dari laptop
- [ ] `public/hot` dihapus di server setelah upload
- [ ] `php artisan optimize:clear` + tes halaman kritis

---

## Jawaban singkat

- **Path lokal yang ditemukan:** hanya **`C:\Pelayaran\`** (FileZilla) + **`C:\ocean\`** (terlalu lama) — **bukan** salinan stabil 19 Juni.
- **`AppServiceProvider.php` di `C:\Pelayaran`:** **1372 byte**, **20/06/2026 01:30** — workspace pasca-deploy rusak, bukan bukti versi pagi 19–20 Juni.
- **Restore via FileZilla:** unduh dari **`backup/`** / arsip di **remote** (atau upload dari folder restore lokal yang baru diunduh), **bukan** upload mirror `C:\Pelayaran` saat ini.

Lihat juga: `FILEZILLA-RESTORE-19-JUNI.md` (audit paralel), `RECOVERY-TANPA-BACKUP.md`, `tools/check-server-backups.sh` (daftar folder backup di server).

*Dibuat dari pencarian path FileZilla + filesystem Windows — 20 Juni 2026.*
