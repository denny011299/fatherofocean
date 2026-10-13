# Restore Produksi dari Salinan Lokal FileZilla (19 Juni 2026)

Dokumen ini menjawab: **apakah upload FileZilla tanggal 19 Juni bisa dipakai untuk mengembalikan server production**, dan langkah praktis jika salinan lokal masih ada.

---

## Hasil pengecekan di komputer ini (20 Juni 2026)

| Item | Temuan |
|------|--------|
| **Folder lokal FileZilla (panel kiri) terakhir** | `C:\Pelayaran\` (dari `filezilla.xml`, koneksi `ftp.oceanlink.my.id`) |
| **Salinan terpisah khusus 19 Juni** | **Tidak ditemukan** (tidak ada folder duplikat `Pelayaran`, `oceanlink`, atau `public_html` di Downloads/Desktop/Documents) |
| **FileZilla `recents.xml`** | Tidak ada di `%AppData%\Roaming\FileZilla\` |
| **Riwayat antrian transfer (`queue.sqlite3`)** | Kosong (0 file) — tidak menyimpan daftar upload 19 Juni |
| **Cache FileZilla** | Hanya ikon UI, bukan salinan kode aplikasi |
| **Git di `C:\Pelayaran`** | Bukan repositori git |

### Bukti bahwa `C:\Pelayaran` sudah tertimpa (bukan snapshot 19 Juni)

- Folder **`app\`** terakhir diubah **20 Juni 2026 ~01:30**; **177** file di bawah `app\` bertanggal **20 Juni**, **0** file bertanggal **19 Juni**.
- Masih ada **205** file lain dengan tanggal modifikasi **19 Juni** (mis. sebagian `resources\`, `lang\`, `tests\`, `public\`), tetapi **kode inti aplikasi (`app\`) sudah versi 20 Juni**.
- Artinya: **folder yang sama dipakai upload kemarin, tetapi isinya sekarang sudah berubah** — FileZilla **tidak** menyimpan “versi kemarin” otomatis.

**Kesimpulan singkat:** Di mesin ini **tidak ada** salinan lokal lengkap per **19 Juni 2026** yang aman untuk restore penuh. Yang ada hanya **`C:\Pelayaran`** dalam keadaan **campuran / sudah di-overwrite** pada malam 20 Juni.

---

## Cara mencari folder lokal “versi 19 Juni” di FileZilla

FileZilla **tidak** punya fitur “kembalikan ke tanggal upload”. Yang bisa dicek hanya **folder di panel lokal (kiri)** saat ini atau salinan yang **Anda simpan sendiri** di disk.

### 1. Buka path lokal terakhir

1. Buka **FileZilla**.
2. Sambung ke situs **Ocean - Tanaya** (`ftp.oceanlink.my.id`).
3. Di **panel kiri (Local site)**, lihat path di bagian atas — biasanya **`C:\Pelayaran\`**.
4. Menu **File → Site Manager** → pilih situs oceanlink → tab **Advanced** (jika ada) → cek **Default local directory**.

### 2. Bandingkan dengan server (opsional)

1. Panel kanan: buka folder remote production (biasanya **`public_html`** atau root domain).
2. Menu **View → Directory comparison** → bandingkan isi lokal vs remote.
3. Ini hanya menunjukkan **perbedaan sekarang**, bukan isi server pada 19 Juni.

### 3. Cari salinan manual di Windows

Jika kemarin Anda **download** ke folder lain (bukan hanya upload dari `C:\Pelayaran`):

- Cari folder yang berisi **`artisan`**, **`app\Http\Controllers`**, **`composer.json`**.
- Di komputer ini, pencarian di `Downloads`, `Desktop`, `Documents`, dan nama folder `public_html` **tidak menemukan** salinan Pelayaran/oceanlink selain `C:\Pelayaran`.

---

## Jika Anda menemukan folder lokal lengkap (bukan `C:\Pelayaran` yang rusak)

Lakukan hanya jika folder itu **benar-benar** snapshot utuh (ada `app`, `routes`, `config`, `vendor` atau rencana `composer install`, dll.).

### Di server (via FileZilla / SSH)

1. **Backup dulu** folder remote yang rusak:
   - Rename **`public_html`** → **`public_html_rusak_20260620`** (atau tanggal hari ini).
2. Buat folder **`public_html`** kosong (atau upload ke folder baru lalu rename).
3. **Upload seluruh isi** folder lokal snapshot (bukan hanya `app`) ke **`public_html`**.

### Yang **JANGAN** ditimpa dari upload lokal

| Path di server | Alasan |
|----------------|--------|
| **`.env`** | Kredensial DB, `APP_KEY`, mail — milik server production |
| **`storage/app/public`** (dan upload user) | File upload pelanggan / dokumen |
| **`storage/logs`** | Opsional: biarkan log server |

**Praktis di FileZilla:** saat upload, **skip** atau jangan centang upload `.env` dari PC; setelah upload, pastikan `.env` di server masih yang lama.

### Setelah upload

Jalankan di server (SSH atau terminal hosting), dari root Laravel (`public_html`):

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Jika `vendor` tidak ikut di-upload:

```bash
composer install --no-dev --optimize-autoloader
```

---

## Jika salinan 19 Juni **tidak** ada di PC (situasi saat ini)

FileZilla **tidak bisa time-travel**. Upload kemarin hanya mengirim **apa yang ada di folder lokal saat itu**; setelah folder lokal berubah, versi 19 Juni di PC **hilang** kecuali ada backup lain.

### Alternatif restore (disarankan)

1. **JetBackup / backup cPanel** hosting `oceanlink.my.id` — restore **`public_html`** (atau full account) ke snapshot **18–19 Juni 2026** sebelum overwrite.
2. **Previous Versions / Shadow Copy** di Windows (butuh hak Administrator): klik kanan folder `C:\Pelayaran` → Properties → tab **Previous Versions** — jika ada snapshot 19 Juni, restore ke folder **baru** dulu, jangan langsung timpa.
3. **Backup cloud / ZIP lama** — di `C:\Pelayaran` hanya ada zip **`vendor*.zip`** (Mei 2026), **bukan** full app 19 Juni.
4. **Perbaikan selektif** — jika JetBackup tidak ada, pulihkan file per file dari dokumentasi internal (`RESTORE-STABLE-MORNING.md`, dll.) atau dari mesin/dev lain yang masih sehat.

---

## Jawaban langsung untuk pertanyaan “ambil data upload FileZilla 19 Juni?”

| Pertanyaan | Jawaban |
|------------|---------|
| Apakah FileZilla menyimpan file versi 19 Juni? | **Tidak** (default). |
| Apakah ada folder lokal 19 Juni di PC ini? | **Tidak ditemukan** path terpisah; hanya **`C:\Pelayaran\`**, dan **`app\` sudah 20 Juni**. |
| Bisa restore production dari upload kemarin lewat FileZilla saja? | **Tidak aman** dengan isi `C:\Pelayaran` sekarang — risiko menimpa server dengan kode yang sama rusaknya. |
| Langkah terbaik? | **JetBackup / snapshot hosting** ke tanggal sebelum rusak, atau restore Windows Previous Versions jika tersedia. |

---

## Checklist cepat sebelum upload apa pun lagi

- [ ] Sudah punya snapshot server (`public_html_rusak` atau JetBackup)?
- [ ] Folder lokal diverifikasi: `app\Http\Controllers` tanggalnya sesuai versi yang diinginkan?
- [ ] `.env` dan `storage/app/public` dikecualikan dari upload?
- [ ] Setelah deploy: `php artisan optimize:clear` + tes login dan satu modul kritis?

---

*Dibuat otomatis dari audit path FileZilla dan timestamp file di `C:\Pelayaran` pada 20 Juni 2026.*
