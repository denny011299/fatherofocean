# Status Perbaikan — Penilaian Jujur (20 Juni 2026)

> **Pertanyaan:** Apakah perbaikan yang direkonstruksi dari transcript Cursor **logis dan benar**, bukan stub ngawur?
>
> **Jawaban singkat:** **Sebagian ya, sebagian belum.** Beberapa modul punya bukti kuat (tes lulus, spec jelas). Beberapa file kritis **masih tidak lengkap atau tidak terhubung** — deploy tanpa perbaikan tambahan **berisiko crash atau perilaku salah**.

**Tingkat keyakinan keseluruhan: 55–65%** (cukup untuk lanjut recovery, **belum** cukup untuk jaminan 100% tanpa backup restore atau tes penuh di server).

---

## Apa yang memberi keyakinan

| Bukti | Artinya |
|-------|---------|
| **Unit test lulus** (7 tes) | `BarangEditRequestService` — diff produk, gate L/W/H-only, preserve foto |
| **Unit test lulus** (2 tes) | `OmsetSpreadsheetBuilder` — layout export omset |
| **Spec tertulis** | `tools/patches/lwh-only-pengajuan.md` selaras dengan implementasi service |
| **Grep view ↔ model** | Dashboard memanggil `hasCompleteProductList()` — intent bisnis jelas |
| **Unit test sebagai spec** | `BarangProductListCompletenessTest` mendefinisikan aturan kelengkapan daftar produk |
| **Integrasi komisi** | `SupervisorMarketingCommissionTest` + `SalesRevenueCalculator` selaras dengan `User::earnsCapitalGainOnly()` |
| **Syntax OK** | `php tools/verify-deploy.php` — semua file `app/` lolos `php -l` |
| **Cache PHPUnit lama** | Beberapa tes (MarkingPricingResolver, dll.) pernah PASS di mesin dengan DB — implementasi resolver selaras dengan ekspektasi tes |

---

## Apa yang TIDAK memberi jaminan 100%

| Risiko | Penjelasan |
|--------|------------|
| **Tidak ada Git / backup lokal** | Kode direkonstruksi dari transcript + potongan file — bisa beda dari production stabil |
| **Upload file-per-file** | Penyebab awal crash; partial deploy bisa ulang masalah yang sama |
| **Tes DB tidak jalan di laptop** | PHP lokal punya `pdo_mysql` tapi **tidak** `pdo_sqlite` — banyak unit/feature test gagal di sini bukan karena logic, tapi environment |
| **File `.full.php` / `.reconstructed.php`** | Salinan lengkap ada di repo tapi **belum** jadi file aktif yang dipakai runtime |

---

## Hasil audit per komponen

### 1. `User::earnsCapitalGainOnly()`

| Aspek | Status |
|-------|--------|
| Ada di `app/Models/User.php` | ✅ |
| Implementasi | `return $this->hasRole('supervisor');` |
| Tes langsung pada method | ❌ tidak ada |
| Tes integrasi | ✅ `SupervisorMarketingCommissionTest` — supervisor sebagai marketing dapat capital gain, bukan komisi % |
| Pemanggil | `SalesRevenueCalculator` baris ~185, ~200 |

**Penilaian:** Logika **masuk akal** dan selaras dengan tes integrasi. Bukan stub kosong. Keyakinan **tinggi (~85%)** untuk method ini saja.

---

### 2. `Barang::hasCompleteProductList()`

| Aspek | Status |
|-------|--------|
| Dipakai di `dashboard/admin.blade.php` | ✅ 4 pemanggilan |
| Ada di `app/Models/Barang.php` (aktif) | ❌ **TIDAK ADA** |
| Ada di `app/Models/Barang.full.php` | ✅ ~453–514 (logic lengkap) |
| Unit test | `BarangProductListCompletenessTest` — 4 skenario |

**Aturan bisnis (dari tes + `Barang.full.php`):**

- Daftar kosong → tidak lengkap
- Parent: butuh nama, CTNS, GW, L/W/H, foto
- Sub-item: butuh nama, PCS, foto

**Masalah tambahan:** `Barang.full.php` memanggil `$product->normalizedPhotoPath()`, tapi method itu **tidak ada** di `BarangProduct.php` aktif (tes `BarangProductPhotoUrlTest` mengharapkannya).

**Penilaian:** Intent bisnis **jelas dan konsisten** antara tes, blade, dan `Barang.full.php`. Tapi file aktif `Barang.php` cuma **145 baris** vs **1599 baris** di `.full.php` — **dashboard akan error** `Call to undefined method` jika deploy apa adanya. Keyakinan deploy-ready: **rendah (~20%)** sampai `Barang.php` diganti/dimerge.

---

### 3. `BarangEditRequestService` (gate L/W/H pengajuan)

| Aspek | Status |
|-------|--------|
| File lengkap (~920 baris) | ✅ |
| `requiresApproval()` — hanya L/W/H | ✅ selaras spec |
| Unit test | ✅ **7/7 PASS** (tanpa butuh DB untuk skenario ini) |

**Penilaian:** Modul ini **paling terpercaya** dari semua rekonstruksi. Bukan ngawur. Keyakinan **tinggi (~90%)**.

---

### 4. `BarangController` ↔ service (integrasi)

| Aspek | Status |
|-------|--------|
| `submitBarangEditRequest()` ada | ✅ |
| `prepareBarangUpdate()` ada | ✅ |
| Route `PUT barang/{barang}` → `update()` | ⚠️ **Method `update()` baris 255–308 adalah versi stub** — validasi minimal, langsung `$barang->update()`, **tanpa** produk, **tanpa** `requiresApproval()`, **tanpa** `saved_directly` / `submitted_for_approval` |
| Feature test `BarangEditApprovalTest` | Mengharapkan alur pengajuan via `route('barang.update')` — **akan gagal** dengan stub saat ini |

**Penilaian:** Service benar, **controller belum ter-wire**. Ini risiko **sangat tinggi** — edit barang di production tidak akan mengikuti alur pengajuan L/W/H meskipun service sudah benar. Keyakinan alur end-to-end: **rendah (~30%)**.

---

### 5. `MarkingPricingResolver` + `MarkingBarangPricing`

| Aspek | Status |
|-------|--------|
| Class & method ada | ✅ `normalizeKey`, `legacyCbmTypeAlias`, `pickRow`, `modalRatesFromPricingRow` |
| `MarkingBarangPricing::applyToRequest` | ✅ logic snapshot harga marking |
| Unit test | Tidak bisa dijalankan di laptop (no sqlite driver); cache lama menunjukkan pernah PASS |
| Keselarasan kode ↔ tes | ✅ dibaca manual — alias `cbm→umum`, pick by pricing_item_id, no fallback untuk unknown type |

**Penilaian:** Implementasi **terlihat benar** dan bukan stub. Keyakinan **sedang-tinggi (~75%)** — perlu konfirmasi dengan `php artisan test` di server (MySQL/sqlite enabled).

---

### 6. `OmsetSpreadsheetBuilder`

| Aspek | Status |
|-------|--------|
| Unit test | ✅ **2/2 PASS** |

**Penilaian:** Keyakinan **tinggi (~95%)**.

---

### 7. `php tools/verify-deploy.php`

```
FAIL — 3 error(s):
  • App\Models\Marking::visibleToUser() missing
  • App\Models\Marking::whereBarangMarkingValue() missing
  • App\Models\Invoice::fullyPaid() missing
```

**Penjelasan:** Ini **false positive**. Ketiga itu adalah **Eloquent scope** (`scopeVisibleToUser`, `scopeWhereBarangMarkingValue`, `scopeFullyPaid`) — **sudah ada** di `Marking.php` dan `Invoice.php`. Validator cek `method_exists()` tanpa memahami konvensi Laravel scope.

Syntax check semua file `app/`:** PASS**.

---

## Ringkasan tes (laptop, 20 Juni 2026)

| Test file | Hasil | Catatan |
|-----------|-------|---------|
| `BarangEditRequestProductDiffTest` | ✅ 7/7 | Tidak butuh DB untuk skenario utama |
| `OmsetSpreadsheetBuilderTest` | ✅ 2/2 | |
| `MarkingPricingResolverPickRowTest` | ❌ | `could not find driver` (sqlite) |
| `BarangProductListCompletenessTest` | ❌ | sqlite + method hilang di Barang aktif |
| `MarkingBarangPricingSnapshotTest` | ❌ | sqlite |

---

## Area RISIKO TINGGI (rekonstruksi transcript)

1. **`app/Models/Barang.php`** — versi aktif sangat terpotong; `Barang.full.php` belum dipromosikan
2. **`BarangController::update()`** — stub tidak memanggil alur pengajuan; private method lengkap “menganggur”
3. **`BarangProduct::normalizedPhotoPath()`** — diharapkan tes + `Barang.full.php`, belum ada
4. **File besar dirakit dari potongan** — `BarangController.php` (2035 baris), kemungkinan duplikat/method salah tempat
5. **Tanpa backup restore** — tidak ada ground truth untuk banding byte-per-byte

---

## Rekomendasi (urutan prioritas)

### 🥇 Jika memungkinkan: restore hosting (JetBackup/cPanel)

Restore `public_html` ke **19 Juni malam** atau **20 Juni sebelum deploy rusak**. Ini satu-satunya jalan ke **keyakinan ~95%+**.

### 🥈 Jika lanjut dari workspace lokal

Sebelum upload:

1. **Promosikan `Barang.full.php` → `Barang.php`** (atau merge manual), tambahkan `normalizedPhotoPath()` di `BarangProduct`
2. **Perbaiki `BarangController::update()`** — wire `prepareBarangUpdate` + `requiresApproval` + `submitBarangEditRequest` / `executePreparedBarangUpdate` sesuai `tools/patches/lwh-only-pengajuan.md`
3. **Deploy SELURUH folder `app/`** sekaligus — jangan file per file
4. Di **server**: `php tools/verify-deploy.php` (abaikan 3 false positive scope), lalu:
   ```bash
   php artisan test tests/Unit/BarangEditRequestProductDiffTest.php
   php artisan test tests/Unit/BarangProductListCompletenessTest.php
   php artisan test tests/Feature/BarangEditApprovalTest.php
   php artisan test tests/Unit/SupervisorMarketingCommissionTest.php
   php artisan test tests/Unit/MarkingPricingResolverPickRowTest.php
   ```
5. `rm -f public/hot && php artisan optimize:clear`

---

## Verdict untuk user

| Pertanyaan | Jawaban |
|------------|---------|
| Apakah **alur bisnis** yang direkonstruksi ngawur? | **Tidak** — spec tes, blade, dan service menunjukkan intent yang konsisten |
| Apakah **semua file siap deploy**? | **Belum** — `Barang.php` terpotong, controller `update()` stub, method foto produk hilang |
| Apakah bisa dijamin 100% sama production? | **Tidak**, tanpa backup restore atau tes penuh di server |
| Keyakinan level | **55–65%** workspace; **90%+** untuk modul terisolasi (EditRequest service, Omset builder); **<30%** untuk alur edit barang end-to-end |

**Kesimpulan jujur:** Perbaikan ini **bukan stub random** — ada fondasi logis dan tes yang mendukung. Tapi rekonstruksi **belum utuh**: beberapa bagian benar (service), beberapa **belum terhubung atau belum dipromosikan ke file aktif** (model Barang, controller update). **Deploy bundle penuh setelah perbaikan 3 poin di atas**, atau **restore backup hosting** — itu dua jalan paling aman.

---

*Dibuat otomatis dari audit: compare tests, verify-deploy, grep views, inspeksi Barang.php vs Barang.full.php, BarangController update flow.*
