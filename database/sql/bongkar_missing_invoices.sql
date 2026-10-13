-- =============================================================================
-- Barang sudah bongkar tapi belum punya invoice (belum ada baris invoice_items)
-- =============================================================================
--
-- RINGKASAN CEPAT
--   Cek     : jalankan query A1 + A5 di bawah (MySQL, read-only)
--   Container muat tanpa invoice: database/sql/invoice_bongkar_container.sql (C1–C3)
--   Buat    : php artisan invoices:backfill-bongkar --dry-run
--             php artisan invoices:backfill-bongkar
--   Script  : bash database/sql/bongkar_backfill_invoices.sh
--   Dokumen : database/sql/README.md
--
-- Kriteria aplikasi (Barang::createInvoiceIfNeeded / invoices:backfill-bongkar):
--   - barangs.status = 'bongkar'
--   - belum ada invoice_items.barang_id
--   - user_id NOT NULL
--   - price_per_cbm > 0
-- Pembuatan invoice lewat SQL mentah TIDAK disarankan (invoice_no, konsolidasi
-- container/marking, line_total, recalculate). Gunakan artisan di Part B.
--
-- Cara pakai: jalankan query Part A satu per satu di MySQL (read-only).
--
-- db_block_reason (A2/A3) = hanya kolom barangs.user_id & price_per_cbm (sama
--   Barang::invoiceSkipReasonOnBongkar). NULL = siap backfill.
-- projected_block_reason (A2) = perkiraan setelah resolve marking (dry-run PHP).
-- Lihat invoice_bongkar_container.sql untuk penjelasan db vs projected lengkap.
--
-- Subquery containers: gunakan container_no, fallback partai (bukan `code`).
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Part A — Diagnostik
-- -----------------------------------------------------------------------------

-- A1. Ringkasan: total bongkar tanpa invoice vs eligible vs terblokir
SELECT
    COUNT(*) AS total_bongkar_tanpa_invoice,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) > 0 THEN 1
        ELSE 0
    END) AS eligible_backfill,
    SUM(CASE WHEN b.user_id IS NULL THEN 1 ELSE 0 END) AS blocked_missing_user,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) <= 0 THEN 1
        ELSE 0
    END) AS blocked_price_per_cbm_zero
FROM barangs b
WHERE b.status = 'bongkar'
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  );

-- A2. Daftar barang bongkar tanpa invoice (semua, termasuk yang terblokir)
SELECT
    b.id AS barang_id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.user_id AS barang_user_id,
    m.user_id AS marking_user_id,
    COALESCE(b.user_id, m.user_id) AS projected_user_id,
    COALESCE(u.name, b.customer_name) AS db_customer_label,
    COALESCE(u.name, u_m.name, b.customer_name) AS projected_customer_label,
    b.status,
    b.total_cbm,
    b.price_per_cbm,
    b.insurance_value,
    b.insurance_rate,
    b.extra_charge,
    b.tanggal_masuk,
    b.updated_at AS barang_updated_at,
    CASE
        WHEN b.user_id IS NULL THEN 'no_customer'
        WHEN COALESCE(b.price_per_cbm, 0) <= 0 THEN 'no_price'
        ELSE NULL
    END AS db_block_reason,
    CASE
        WHEN COALESCE(b.user_id, m.user_id) IS NULL THEN 'no_customer'
        WHEN GREATEST(
                COALESCE(b.price_per_cbm, 0),
                COALESCE((
                    SELECT mp.volume_price
                    FROM marking_pricings mp
                    WHERE mp.marking_id = m.id
                    ORDER BY mp.id DESC
                    LIMIT 1
                ), 0)
            ) <= 0
        THEN 'no_price'
        ELSE 'projected_eligible'
    END AS projected_block_reason,
    (
        SELECT GROUP_CONCAT(
            DISTINCT COALESCE(NULLIF(TRIM(c.container_no), ''), NULLIF(TRIM(c.partai), ''))
            ORDER BY c.id SEPARATOR ', '
        )
        FROM container_barangs cb
        INNER JOIN containers c ON c.id = cb.container_id
        WHERE cb.barang_id = b.id
    ) AS containers
FROM barangs b
LEFT JOIN markings m ON m.code = TRIM(b.marking)
LEFT JOIN users u ON u.id = b.user_id
LEFT JOIN users u_m ON u_m.id = m.user_id
WHERE b.status = 'bongkar'
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
ORDER BY marking, projected_customer_label, b.id;

-- A3. Hanya yang terblokir di DB (perlu perbaikan data sebelum backfill)
SELECT
    b.id AS barang_id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.user_id AS barang_user_id,
    COALESCE(u.name, b.customer_name) AS db_customer_label,
    b.price_per_cbm,
    CASE
        WHEN b.user_id IS NULL THEN 'no_customer'
        WHEN COALESCE(b.price_per_cbm, 0) <= 0 THEN 'no_price'
        ELSE NULL
    END AS db_block_reason
FROM barangs b
LEFT JOIN users u ON u.id = b.user_id
WHERE b.status = 'bongkar'
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
  AND (
      b.user_id IS NULL
      OR COALESCE(b.price_per_cbm, 0) <= 0
  )
ORDER BY db_block_reason, marking, b.id;

-- A4. Agregat per marking + customer (user_id)
SELECT
    COALESCE(NULLIF(TRIM(b.marking), ''), '(tanpa marking)') AS marking,
    b.user_id,
    COALESCE(u.name, b.customer_name, '(tanpa user)') AS customer_label,
    COUNT(*) AS barang_count,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) > 0 THEN 1
        ELSE 0
    END) AS eligible_count,
    SUM(CASE WHEN b.user_id IS NULL THEN 1 ELSE 0 END) AS missing_user_count,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) <= 0 THEN 1
        ELSE 0
    END) AS zero_price_count,
    ROUND(SUM(b.total_cbm), 4) AS sum_total_cbm
FROM barangs b
LEFT JOIN users u ON u.id = b.user_id
WHERE b.status = 'bongkar'
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
GROUP BY
    COALESCE(NULLIF(TRIM(b.marking), ''), '(tanpa marking)'),
    b.user_id,
    COALESCE(u.name, b.customer_name, '(tanpa user)')
ORDER BY barang_count DESC, marking, customer_label;

-- A5. Sama filter dengan artisan backfill (siap diproses)
SELECT
    b.id AS barang_id,
    b.no_index,
    b.marking,
    b.user_id,
    COALESCE(u.name, b.customer_name) AS customer_label,
    b.total_cbm,
    b.price_per_cbm,
    b.updated_at
FROM barangs b
LEFT JOIN users u ON u.id = b.user_id
WHERE b.status = 'bongkar'
  AND b.user_id IS NOT NULL
  AND b.price_per_cbm > 0
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
ORDER BY b.id;

-- A6. (Opsional) Cek draft invoice terbuka customer yang mungkin konsolidasi
--     Barang eligible bisa masuk invoice draft/sent yang sudah ada (bukan INSERT baru).
SELECT
    b.id AS barang_id,
    b.marking,
    b.user_id,
    inv.id AS open_invoice_id,
    inv.invoice_no,
    inv.status AS invoice_status,
    inv.grand_total
FROM barangs b
INNER JOIN invoices inv
    ON inv.user_id = b.user_id
   AND inv.status IN ('draft', 'sent')
WHERE b.status = 'bongkar'
  AND b.user_id IS NOT NULL
  AND b.price_per_cbm > 0
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
  AND EXISTS (
      SELECT 1
      FROM invoice_items ii2
      INNER JOIN barangs b2 ON b2.id = ii2.barang_id
      WHERE ii2.invoice_id = inv.id
        AND ii2.barang_id <> b.id
        AND COALESCE(NULLIF(TRIM(b2.marking), ''), '') = COALESCE(NULLIF(TRIM(b.marking), ''), '')
  )
ORDER BY b.id, inv.id;

-- -----------------------------------------------------------------------------
-- Part B — Perbaikan aman (disarankan via aplikasi, BUKAN INSERT invoice manual)
-- -----------------------------------------------------------------------------
-- Preview jumlah yang akan diproses:
--   php artisan invoices:backfill-bongkar --dry-run
--
-- Buat / gabung draft invoice (konsolidasi, invoice_no, line_total, recalculate):
--   php artisan invoices:backfill-bongkar
--
-- Filter opsional:
--   php artisan invoices:backfill-bongkar --user=123 --dry-run
--   php artisan invoices:backfill-bongkar --marking=ABC --limit=100
--
-- Setelah backfill, verifikasi dengan query A1/A5 (eligible harus 0 atau berkurang).
--
-- Cron opsional (lihat database/sql/README.md):
--   bootstrap/app.php -> withSchedule(fn ($s) => $s->command('invoices:backfill-bongkar')->dailyAt('02:00'))
--   Server: * * * * * cd /path/to/app && php artisan schedule:run

-- -----------------------------------------------------------------------------
-- Part C — Opsional: perbaikan data blocking (HANYA jika harga sudah benar di sistem)
-- -----------------------------------------------------------------------------
-- JANGAN insert ke invoices / invoice_items secara manual.
-- Contoh: set harga CBM yang sengaja 0 di DB padahal seharusnya ada (sesuaikan ID & nilai):

-- UPDATE barangs
-- SET price_per_cbm = 650000.00,
--     updated_at = NOW()
-- WHERE id = 12345
--   AND status = 'bongkar'
--   AND user_id IS NOT NULL
--   AND COALESCE(price_per_cbm, 0) <= 0
--   AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE barang_id = barangs.id);
-- Lalu: php artisan invoices:backfill-bongkar --dry-run

-- Contoh: hubungkan customer jika user_id kosong (WAJIB validasi marking/user dulu):

-- UPDATE barangs
-- SET user_id = 999,
--     updated_at = NOW()
-- WHERE id = 12345
--   AND status = 'bongkar'
--   AND user_id IS NULL
--   AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE barang_id = barangs.id);
-- Lalu: php artisan invoices:backfill-bongkar
