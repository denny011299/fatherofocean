-- =============================================================================
-- Barang sudah masuk (stuffing+) tapi belum punya invoice (belum ada invoice_items)
-- =============================================================================
--
-- TANPA TERMINAL — pakai phpMyAdmin / Navicat:
--   1. Jalankan query Part A di file ini (read-only, aman).
--   2. Buat invoice: buka file masuk_create_invoices_insert.sql
--      → ikuti langkah di header file itu (Part B2 GROUPED disarankan).
--
-- DENGAN TERMINAL (opsional):
--   php artisan invoices:backfill-masuk --dry-run
--   php artisan invoices:backfill-masuk
--
-- ATAU lewat browser (super_admin, setelah deploy kode terbaru):
--   Finance → tombol "Sync Invoice Masuk Barang"
--
-- Kriteria aplikasi (Barang::createInvoiceIfNeeded / invoices:backfill-masuk):
--   - barangs.status IN ('stuffing','inbound','etd','eta','bongkar')
--   - belum ada invoice_items.barang_id
--   - user_id NOT NULL (atau bisa di-resolve dari marking saat backfill PHP)
--   - price_per_cbm > 0 (atau bisa di-resolve dari marking pricing saat backfill PHP)
--
-- Partai di Invoice & Pembayaran:
--   - Barang belum dimuat ke container → kolom partai invoice = '-'
--   - Setelah dimuat → partai terisi otomatis (Invoice::resolvedPartaiLabel)
--
-- Pembuatan invoice lewat SQL mentah TIDAK disarankan (invoice_no, konsolidasi
-- marking/container, line_total, recalculate). Gunakan artisan di Part B.
--
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Part A — Diagnostik
-- -----------------------------------------------------------------------------

-- A1. Ringkasan: total masuk barang tanpa invoice vs eligible vs terblokir
SELECT
    COUNT(*) AS total_masuk_tanpa_invoice,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) > 0 THEN 1
        ELSE 0
    END) AS eligible_backfill,
    SUM(CASE WHEN b.user_id IS NULL THEN 1 ELSE 0 END) AS blocked_missing_user,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) <= 0 THEN 1
        ELSE 0
    END) AS blocked_price_per_cbm_zero,
    SUM(CASE
        WHEN NOT EXISTS (
            SELECT 1 FROM container_barangs cb WHERE cb.barang_id = b.id
        ) THEN 1
        ELSE 0
    END) AS belum_dimuat_partai_minus
FROM barangs b
WHERE b.status IN ('stuffing', 'inbound', 'etd', 'eta', 'bongkar')
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  );

-- A2. Daftar barang masuk tanpa invoice (semua, termasuk yang terblokir)
SELECT
    b.id AS barang_id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.status,
    b.user_id AS barang_user_id,
    m.user_id AS marking_user_id,
    COALESCE(b.user_id, m.user_id) AS projected_user_id,
    COALESCE(u.name, b.customer_name) AS db_customer_label,
    b.total_cbm,
    b.price_per_cbm,
    b.tanggal_masuk,
    CASE
        WHEN EXISTS (SELECT 1 FROM container_barangs cb WHERE cb.barang_id = b.id)
        THEN (
            SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(c.partai), '') ORDER BY c.id SEPARATOR ', ')
            FROM container_barangs cb
            INNER JOIN containers c ON c.id = cb.container_id
            WHERE cb.barang_id = b.id
        )
        ELSE '-'
    END AS partai_label,
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
    END AS projected_block_reason
FROM barangs b
LEFT JOIN markings m ON m.code = TRIM(b.marking)
LEFT JOIN users u ON u.id = b.user_id
WHERE b.status IN ('stuffing', 'inbound', 'etd', 'eta', 'bongkar')
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
ORDER BY b.tanggal_masuk DESC, marking, b.id;

-- A3. Hanya yang terblokir di DB (perlu perbaikan data sebelum backfill)
SELECT
    b.id AS barang_id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.status,
    b.user_id,
    COALESCE(u.name, b.customer_name) AS customer_label,
    b.price_per_cbm,
    CASE
        WHEN b.user_id IS NULL THEN 'no_customer'
        WHEN COALESCE(b.price_per_cbm, 0) <= 0 THEN 'no_price'
        ELSE NULL
    END AS db_block_reason
FROM barangs b
LEFT JOIN users u ON u.id = b.user_id
WHERE b.status IN ('stuffing', 'inbound', 'etd', 'eta', 'bongkar')
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
  AND (
      b.user_id IS NULL
      OR COALESCE(b.price_per_cbm, 0) <= 0
  )
ORDER BY db_block_reason, marking, b.id;

-- A4. Agregat per marking + customer
SELECT
    COALESCE(NULLIF(TRIM(b.marking), ''), '(tanpa marking)') AS marking,
    b.user_id,
    COALESCE(u.name, b.customer_name, '(tanpa user)') AS customer_label,
    COUNT(*) AS barang_count,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) > 0 THEN 1
        ELSE 0
    END) AS eligible_count,
    SUM(CASE
        WHEN NOT EXISTS (SELECT 1 FROM container_barangs cb WHERE cb.barang_id = b.id) THEN 1
        ELSE 0
    END) AS belum_dimuat_count,
    ROUND(SUM(b.total_cbm), 4) AS sum_total_cbm
FROM barangs b
LEFT JOIN users u ON u.id = b.user_id
WHERE b.status IN ('stuffing', 'inbound', 'etd', 'eta', 'bongkar')
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
GROUP BY
    COALESCE(NULLIF(TRIM(b.marking), ''), '(tanpa marking)'),
    b.user_id,-+
    COALESCE(u.name, b.customer_name, '(tanpa user)')
ORDER BY barang_count DESC, marking, customer_label;

-- A5. Sama filter dengan artisan backfill (siap diproses)
SELECT
    b.id AS barang_id,
    b.no_index,
    b.marking,
    b.status,image.png
    b.user_id,
    COALESCE(u.name, b.customer_name) AS customer_label,
    b.total_cbm,
    b.price_per_cbm,
    b.tanggal_masuk,
    CASE
        WHEN EXISTS (SELECT 1 FROM container_barangs cb WHERE cb.barang_id = b.id) THEN 'ada'
        ELSE '-'
    END AS partai_label
FROM barangs b
LEFT JOIN users u ON u.id = b.user_id
WHERE b.status IN ('stuffing', 'inbound', 'etd', 'eta', 'bongkar')
  AND b.user_id IS NOT NULL
  AND b.price_per_cbm > 0
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
ORDER BY b.id;

-- -----------------------------------------------------------------------------
-- Part B — Perbaikan aman
-- -----------------------------------------------------------------------------
-- TANPA TERMINAL: buka masuk_create_invoices_insert.sql di phpMyAdmin.
-- DENGAN BROWSER: Finance → tombol "Sync Invoice Masuk" (super_admin).
-- DENGAN TERMINAL (opsional):
--   php artisan invoices:backfill-masuk --dry-run
--   php artisan invoices:backfill-masuk
