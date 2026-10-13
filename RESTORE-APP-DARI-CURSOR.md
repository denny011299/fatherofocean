# Restore app/ dari Cursor History

Dibuat: 20 Juni 2026

## Jawaban singkat

| Pertanyaan | Jawaban |
|---|---|
| Bisa restore **seluruh** `app/` dari history Cursor kemarin? | **Tidak penuh** — hanya **78 dari 179 file (43,6%)** |
| Restore berhasil? | **Sebagian** — 78 file sudah dipulihkan dari Cursor Local History |
| Siap deploy? | **Belum** — `verify-deploy.php` masih 7 error (method hilang di 5 file) |

---

## Sumber yang dicek

| Sumber | Hasil |
|---|---|
| `%APPDATA%\Cursor\User\History\` | **78 file** `app/` dengan snapshot sebelum 20 Juni 2026 00:00 WIB |
| Agent transcripts (`~\.cursor\projects\c-Pelayaran\agent-transcripts\`) | ~166 file PHP bisa di-replay, tapi **versi tidak lengkap** (contoh: `Barang.php` jadi 3,6 KB) — **jangan dipakai sebagai sumber utama** |
| Cursor Backups (`%APPDATA%\Cursor\Backups\`) | **Kosong / tidak ada** |
| Cursor workspaceStorage | Hanya index Intelephense, **bukan backup file** |
| Windows Previous Versions | **Tidak bisa dicek** (butuh PowerShell Administrator + Shadow Copy) |
| Git | **Tidak ada repo git** di `C:\Pelayaran` |
| Timestamp file di disk | 177 file = 20 Juni (korupsi/fix malam ini), 2 file = 14 Juni — **tidak ada file dengan tanggal 19 Juni** |

Snapshot Cursor History terbaik: **19 Juni 2026 ~22:45 WIB** (timestamp `1781883958809`).

---

## Yang sudah dilakukan

1. Backup folder rusak ke `app.broken-20260620-021736\`
2. Restore **78 file** dari Cursor Local History (strategi: **file terbesar** sebelum 20 Juni, bukan timestamp terbaru)
3. Backup sebelum restore ke `app.pre-restore-20260620-021951\`
4. `php -l` pada semua `.php` di `app/` → **0 syntax error**
5. `php tools/verify-deploy.php` → **7 error** (turun dari 14+ sebelum restore)

### File yang berhasil dipulihkan (78)

Controllers, Models, Support, Exports, Middleware, Services — file yang pernah dibuka/diedit di Cursor. Daftar lengkap: jalankan script dengan `-WhatIf`.

### File yang TIDAK ada di Cursor History (102)

Daftar lengkap: `tools/restore-app-missing-from-history.txt`

Folder paling banyak missing:
- `Models/` — 35 file
- `Support/` — 19 file
- `Http/` — 15 file
- `Exports/` — 7 file

File-file ini **tidak pernah tercatat** di Local History Cursor (tidak pernah dibuka lokal, atau hanya ada di server).

---

## Cara restore ulang (jika perlu)

```powershell
# Lihat rencana tanpa menulis file
powershell -ExecutionPolicy Bypass -File tools\restore-app-from-cursor-history.ps1 -WhatIf

# Restore (default: pilih snapshot terbesar sebelum 20 Juni)
powershell -ExecutionPolicy Bypass -File tools\restore-app-from-cursor-history.ps1

# Alternatif: pilih snapshot terbaru sebelum 20 Juni
powershell -ExecutionPolicy Bypass -File tools\restore-app-from-cursor-history.ps1 -UseLatest
```

Setelah restore:

```powershell
cd C:\Pelayaran
Get-ChildItem app -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName 2>$null }
php tools/verify-deploy.php
```

---

## 7 error yang masih tersisa (setelah restore)

```
App\Support\MarkingPricingResolver::modalRatesFromPricingRow()
App\Models\Marking::visibleToUser()
App\Models\Marking::whereBarangMarkingValue()
App\Models\Invoice::fullyPaid()
App\Models\User::commissionRateLabel()
App\Support\Branding::absoluteLogoPath()
```

Method ini **tidak ada** di snapshot Cursor History manapun — kemungkinan ditambahkan setelah 19 Juni malam atau hanya pernah ada di server production.

---

## Alternatif terbaik tanpa JetBackup

### 1. Download dari server production (PRIORITAS)

Jika server masih punya `app/` sebelum deploy malam ini:

```bash
# Via cPanel File Manager atau FTP — download SELURUH folder:
public_html/app/   →   C:\Pelayaran\app\
```

Jangan upload partial — upload **seluruh folder** sekaligus.

### 2. cPanel JetBackup 5

Jalankan di cPanel Terminal:

```bash
bash tools/check-server-backups.sh
```

Restore snapshot **18–19 Juni 2026** jika tersedia.

### 3. Windows Previous Versions (butuh Admin)

1. Klik kanan `C:\Pelayaran\app\` → **Properties** → tab **Previous Versions**
2. Pilih snapshot 19 Juni jika ada
3. Restore ke folder sementara, bandingkan dengan hasil Cursor History

### 4. Hybrid (jika server tidak tersedia)

| Langkah | Sumber |
|---|---|
| 78 file inti | Cursor History (sudah diterapkan) |
| 102 file sisanya | Transcript replay **hati-hati** — cek ukuran file, bandingkan dengan server |
| 7 method hilang | Tambah manual dari transcript `Read` tool output atau fix targeted |

**Jangan** jalankan `tools/restore_all_app_from_transcripts.py` tanpa review — script itu menimpa file dengan versi partial dari operasi Write Cursor.

---

## Backup lokal saat ini

| Folder | Isi |
|---|---|
| `app\` | Hasil restore hybrid (78 dari history + 101 dari state sebelumnya) |
| `app.broken-20260620-021736\` | State setelah transcript replay (partial/rusak) |
| `app.pre-restore-20260620-021951\` | State sebelum restore terakhir (latest-history) |

---

## Rekomendasi ke depan

1. **Inisialisasi git** di `C:\Pelayaran` — commit setiap deploy
2. **Backup otomatis** `app/` ke zip harian sebelum upload FTP
3. **Selalu upload seluruh `app/`**, bukan file per file
4. Jalankan `php tools/verify-deploy.php` sebelum setiap deploy

---

## Status commit

Restore **belum** di-commit (sesuai instruksi — commit hanya jika restore sukses penuh).
