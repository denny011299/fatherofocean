-- =============================================================================
-- EK/ON/SONDAKH (YENNY) — audit & perbaikan tagihan bongkar di production
-- Contoh: no_index 1704.05 (barang_id 83), 2004.05 (barang_id 85)
-- =============================================================================
-- Disarankan: php artisan invoices:backfill-bongkar --marking="EK/ON/SONDAKH"
-- SQL di bawah untuk audit; INSERT invoice lewat artisan atau bongkar_buat_tagihan_simple.sql
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- A1. Audit barang SONDAKH (semua status)
-- -----------------------------------------------------------------------------
SELECT
    b.id AS barang_id,
    b.no_index,
    b.marking,
    b.status,
    b.user_id,
    u.name AS user_account,
    u.marking_code AS portal_marking,
    b.customer_name,
    b.total_cbm,
    b.price_per_cbm,
    b.insurance_value,
    b.insurance_rate,
    b.extra_charge,
    CASE
        WHEN NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id) THEN 'no_invoice_item'
        ELSE 'has_invoice'
    END AS invoice_state,
    (
        SELECT i.invoice_no
        FROM invoice_items ii
        INNER JOIN invoices i ON i.id = ii.invoice_id
        WHERE ii.barang_id = b.id
        LIMIT 1
    ) AS invoice_no
FROM barangs b
LEFT JOIN users u ON u.id = b.user_id
WHERE TRIM(b.marking) = 'EK/ON/SONDAKH'
ORDER BY b.status, b.id;

-- -----------------------------------------------------------------------------
-- A2. Bongkar SONDAKH tanpa invoice (eligible vs blocked)
-- -----------------------------------------------------------------------------
SELECT
    b.id AS barang_id,
    b.no_index,
    b.status,
    b.user_id,
    b.price_per_cbm,
    CASE
        WHEN b.user_id IS NULL THEN 'missing_user_id'
        WHEN COALESCE(b.price_per_cbm, 0) <= 0 THEN 'price_per_cbm_zero'
        ELSE 'eligible_for_backfill'
    END AS block_reason
FROM barangs b
WHERE TRIM(b.marking) = 'EK/ON/SONDAKH'
  AND b.status = 'bongkar'
  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id)
ORDER BY b.id;

-- -----------------------------------------------------------------------------
-- A3. Portal customer untuk marking ini (harus cocok dengan barangs.user_id)
-- -----------------------------------------------------------------------------
SELECT id, name, username, marking_code, is_marking_portal
FROM users
WHERE marking_code = 'EK/ON/SONDAKH'
   OR id IN (SELECT user_id FROM markings WHERE TRIM(code) = 'EK/ON/SONDAKH' AND user_id IS NOT NULL);

-- -----------------------------------------------------------------------------
-- B1. (Opsional) Perbaiki user_id barang YENNY yang masih menempel ke Super Admin (id 1)
-- Jalankan SELECT A1 dulu; commit hanya jika user portal = 55 (atau id dari A3).
-- -----------------------------------------------------------------------------
-- START TRANSACTION;
-- UPDATE barangs b
-- INNER JOIN markings m ON TRIM(m.code) = TRIM(b.marking) AND m.user_id IS NOT NULL
-- SET b.user_id = m.user_id,
--     b.updated_at = NOW()
-- WHERE TRIM(b.marking) = 'EK/ON/SONDAKH'
--   AND b.customer_name = 'YENNY'
--   AND (b.user_id IS NULL OR b.user_id = 1);
-- COMMIT;

-- -----------------------------------------------------------------------------
-- B2. Buat invoice — jalankan di server aplikasi (disarankan):
--   php artisan invoices:backfill-bongkar --marking="EK/ON/SONDAKH" --dry-run
--   php artisan invoices:backfill-bongkar --marking="EK/ON/SONDAKH"
-- -----------------------------------------------------------------------------
-- Alternatif SQL bulk: database/sql/bongkar_buat_tagihan_simple.sql
-- (filter WHERE marking = 'EK/ON/SONDAKH' di query INSERT tmp_buat_tagihan jika perlu)

-- -----------------------------------------------------------------------------
-- C1. Verifikasi setelah backfill (harus ada invoice_no INV-1704.05, INV-2004.05)
-- -----------------------------------------------------------------------------
SELECT
    b.id,
    b.no_index,
    i.invoice_no,
    i.user_id AS invoice_user_id,
    i.status,
    i.grand_total,
    ii.line_total
FROM barangs b
INNER JOIN invoice_items ii ON ii.barang_id = b.id
INNER JOIN invoices i ON i.id = ii.invoice_id
WHERE b.id IN (83, 85)
   OR b.no_index IN ('1704.05', '2004.05');
