# Penjelasan Kegagalan Situs (20 Juni 2026)

> Dokumen ini menjelaskan **mengapa** oceanlink.my.id rusak malam ini, dan **satu-satunya** cara aman kembali ke versi stabil sore/pagi tadi.

---

## Ringkasan Singkat

**Ini BUKAN bug Laravel.** Situs rusak karena **proses deploy yang salah**: file parsial dari laptop (workspace tidak lengkap) di-upload ke production dan menimpa kode yang sudah jalan.

Sore tadi situs **normal**. Malam ini setelah upload FileZilla bertahap, muncul error satu per satu — itu gejala khas **kode tidak lengkap**, bukan kerusakan framework.

---

## Apa yang Terjadi (Kronologi)

| Waktu | Kejadian |
|-------|----------|
| **Sore 20 Juni** | Production stabil — login, dashboard, barang, export, PDF jalan |
| **Malam (~00:00–01:30 WIB)** | Agent Cursor merekonstruksi file dari chat/transcript ke laptop |
| **Setelah itu** | File hasil rekonstruksi di-upload ke server via FileZilla (sering satu file per error) |
| **Akibatnya** | File production tertimpa versi **stub/parsial** → error berantai |

---

## Akar Masalah (5 Poin)

### 1. Workspace laptop tidak lengkap

Folder `c:\Pelayaran` di laptop:

- **Tidak punya git** (tidak ada riwayat commit)
- Banyak file di `app/` **hilang** atau hanya **salinan sebagian**
- Bukan salinan production pagi tadi

### 2. Agent merekonstruksi dari transcript

Malam ini agent mencoba "memperbaiki" dengan menyusun ulang file dari percakapan Cursor. Hasilnya:

- Campuran file **lengkap** + file **stub** (class/method tidak ada)
- Versi tidak konsisten antar file
- Bukan versi yang sama dengan production sore tadi

### 3. Upload parsial ke production

Setiap kali muncul error, satu file diperbaiki lalu di-upload lagi. Ini **menimpa** file bagus di server dengan file tidak lengkap dari laptop.

### 4. Error berantai (domino)

Contoh urutan error yang dilaporkan:

1. `BarangController::edit()` tidak ada
2. CSS hilang (Times New Roman) — biasanya karena `public/hot` atau asset build salah
3. `Locales::name()` tidak ada
4. `Class not found` di PDF/export
5. Konstanta `Barang::STATUS_*` hilang
6. `Undefined variable $salesRevenue` di dashboard
7. Dan seterusnya…

**Satu error diperbaiki → file berikutnya yang masih stub error lagi.** Itu bukti deploy parsial, bukan satu bug tunggal.

### 5. Bukan masalah Laravel / hosting

Database dan server hosting **tidak berubah sendiri**. Yang rusak adalah **file PHP/Blade di `public_html`** karena di-overwrite.

---

## Mengapa "Patch Satu per Satu" Tidak Aman

| Pendekatan | Masalah |
|------------|---------|
| Upload file dari laptop | Laptop tidak punya versi stabil lengkap |
| Fix error lewat agent + upload | Hanya menambah file baru, file lain tetap stub |
| Upload folder `app/` sebagian | Menimpa method/class yang masih ada di server |

**Analogi:** seperti memperbaiki mobil dengan mengganti spare part dari kotak yang isinya tidak lengkap — tiap ganti satu part, part lain yang hilang bikin mesin mati lagi.

---

## Satu-Satunya Cara Aman: Restore Backup cPanel

Versi stabil sore/pagi tadi **masih ada di backup hosting**, bukan di laptop.

### Langkah (singkat)

1. **STOP** upload FileZilla dari laptop — jangan sentuh production
2. Login **cPanel** akun `ocew9596`
3. **JetBackup 5** → Restore → **Home Directory** / `public_html`
4. Pilih backup:
   - **Prioritas:** **19 Juni 2026** (malam), atau
   - **20 Juni sebelum 00:00 WIB**
   - **Jangan** backup setelah 00:30 WIB 20 Juni
5. Restore **file saja** — jangan timpa `.env` dan `storage/app/public/` jika bisa
6. Di Terminal cPanel:

```bash
cd /home/ocew9596/public_html
rm -f public/hot
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

7. Test: login, dashboard, `/barang`, edit barang, export omset

**Panduan lengkap:** lihat [`RESTORE-STABLE-MORNING.md`](RESTORE-STABLE-MORNING.md)

---

## Yang JANGAN Dilakukan

- Upload apapun dari `c:\Pelayaran` ke production
- Deploy file satu per satu lewat FileZilla
- Minta agent "fix error berikutnya" lalu upload lagi
- Restore database kecuali data DB benar-benar hilang (bisa hapus transaksi hari ini)

---

## Setelah Situs Stabil (Nanti)

Baru setelah restore cPanel berhasil:

1. `git init` + commit baseline dari server yang sudah stabil
2. Sebelum deploy berikutnya: `php tools/verify-deploy.php` + `npm run build`
3. Upload **seluruh bundle** sekaligus, bukan per file
4. Pertahankan backup rutin JetBackup

---

## Kesimpulan Satu Kalimat

**Situs rusak karena file production tertimpa kode parsial dari laptop; restore `public_html` dari backup cPanel tanggal 19 Juni — jangan upload dari laptop lagi.**

---

*Dibuat 20 Juni 2026 — untuk user & tim operasional.*
