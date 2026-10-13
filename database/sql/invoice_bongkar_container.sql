-- =============================================================================
-- Invoice — bongkar di container (muat) tanpa tagihan
-- =============================================================================
--
-- Fokus: barang status bongkar yang sudah ada di container_barangs (loading)
--        tetapi belum punya baris invoice_items.
--
-- Backfill (disarankan, bukan INSERT SQL):
--   php artisan invoices:backfill-bongkar --in-container --dry-run
--   php artisan invoices:backfill-bongkar --in-container
--   php artisan invoices:backfill-bongkar --in-container --marking="EK/ON/ABC" --limit=100
--
-- Diagnostik umum (semua bongkar, bukan hanya container):
--   database/sql/bongkar_missing_invoices.sql  (query A1–A6)
--
-- Kriteria artisan (kolom DB saja, tanpa proyeksi marking):
--   status = 'bongkar', tanpa invoice_items, user_id NOT NULL, price_per_cbm > 0
--
-- Kolom db_* / db_block_reason = keadaan AKTUAL tabel barangs saja (sama
--   Barang::invoiceSkipReasonOnBongkar setelah baca DB, TANPA proyeksi):
--   user_id & price_per_cbm di barangs; NULL = siap backfill tanpa --dry-run.
-- Kolom projected_* = perkiraan dry-run PHP (Barang::projectedInvoiceSkipReasonOnBongkar):
--   user/harga bisa diisi dari markings + marking_pricings. Bisa beda sedikit
--   jika cbm_type / pricing_item tidak cocok — gunakan --dry-run untuk angka final.
--
-- JANGAN bandingkan projected_user_id / projected_customer_label dengan
-- db_block_reason: label/nama dari marking BUKAN berarti barangs.user_id terisi.
--
-- Semua query di bawah: SELECT saja (MySQL 8+). Jalankan satu per satu.
--
-- Label container: containers.container_no, fallback containers.partai
-- (kolom lama `code` sudah di-rename ke `partai` lewat migrate).
-- =============================================================================

SET NAMES utf8mb4;

-- Opsional: filter sebelum menjalankan query C1–C4
-- SET @marking_filter = 'EK/ON/SONDAKH';
-- SET @container_id_filter = NULL;   -- contoh: 42
-- SET @user_id_filter = NULL;        -- contoh: 123

-- -----------------------------------------------------------------------------
-- C0. Base CTE — bongkar tanpa invoice + punya relasi container
-- (dipakai ulang di C1–C4; salin blok ini jika client tidak mendukung WITH)
-- -----------------------------------------------------------------------------
/*
WITH pending_container_bongkar AS (
    SELECT
        b.id AS barang_id,
        b.no_index,
        TRIM(b.marking) AS marking,
        b.user_id AS barang_user_id,
        b.customer_name,
        b.status,
        b.total_cbm,
        b.price_per_cbm AS barang_price_per_cbm,
        b.cbm_type,
        b.form,
        b.insurance_value,
        b.insurance_rate,
        b.extra_charge,
        b.tanggal_masuk,
        b.updated_at AS barang_updated_at,
        m.id AS marking_row_id,
        m.user_id AS marking_user_id,
        COALESCE(b.user_id, m.user_id) AS projected_user_id,
        (
            SELECT mp.volume_price
            FROM marking_pricings mp
            WHERE mp.marking_id = m.id
            ORDER BY mp.id DESC
            LIMIT 1
        ) AS marking_volume_price_fallback
    FROM barangs b
    INNER JOIN container_barangs cb ON cb.barang_id = b.id
    LEFT JOIN markings m ON m.code = TRIM(b.marking)
    WHERE b.status = 'bongkar'
      AND NOT EXISTS (
          SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
      )
      AND (@marking_filter IS NULL OR TRIM(b.marking) = @marking_filter)
      AND (@user_id_filter IS NULL OR b.user_id = @user_id_filter OR m.user_id = @user_id_filter)
      AND (
          @container_id_filter IS NULL
          OR cb.container_id = @container_id_filter
      )
)
*/

-- -----------------------------------------------------------------------------
-- C1. Ringkasan container — bongkar tanpa invoice
-- -----------------------------------------------------------------------------
SELECT
    COUNT(DISTINCT b.id) AS total_bongkar_container_tanpa_invoice,
    COUNT(DISTINCT cb.container_id) AS container_count,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND b.price_per_cbm > 0 THEN 1
        ELSE 0
    END) AS eligible_backfill_db_now,
    SUM(CASE WHEN b.user_id IS NULL THEN 1 ELSE 0 END) AS blocked_missing_user_db,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) <= 0 THEN 1
        ELSE 0
    END) AS blocked_price_zero_db,
    SUM(CASE
        WHEN COALESCE(b.user_id, m.user_id) IS NOT NULL
         AND GREATEST(
                COALESCE(b.price_per_cbm, 0),
                COALESCE((
                    SELECT mp.volume_price
                    FROM marking_pricings mp
                    WHERE mp.marking_id = m.id
                    ORDER BY mp.id DESC
                    LIMIT 1
                ), 0)
            ) > 0
        THEN 1
        ELSE 0
    END) AS projected_eligible_after_marking
FROM barangs b
INNER JOIN container_barangs cb ON cb.barang_id = b.id
LEFT JOIN markings m ON m.code = TRIM(b.marking)
WHERE b.status = 'bongkar'
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  );

-- -----------------------------------------------------------------------------
-- C2. Daftar detail per barang + container + alasan terblokir
-- -----------------------------------------------------------------------------
SELECT
    b.id AS barang_id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.user_id AS barang_user_id,
    m.user_id AS marking_user_id,
    COALESCE(b.user_id, m.user_id) AS projected_user_id,
    COALESCE(u_barang.name, b.customer_name) AS db_customer_label,
    COALESCE(u_barang.name, u_marking.name, b.customer_name) AS projected_customer_label,
    b.total_cbm,
    b.price_per_cbm AS barang_price_per_cbm,
    (
        SELECT mp.volume_price
        FROM marking_pricings mp
        WHERE mp.marking_id = m.id
        ORDER BY mp.id DESC
        LIMIT 1
    ) AS marking_volume_price_fallback,
    GREATEST(
        COALESCE(b.price_per_cbm, 0),
        COALESCE((
            SELECT mp.volume_price
            FROM marking_pricings mp
            WHERE mp.marking_id = m.id
            ORDER BY mp.id DESC
            LIMIT 1
        ), 0)
    ) AS projected_price_per_cbm,
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
    c.id AS container_id,
    COALESCE(NULLIF(TRIM(c.container_no), ''), NULLIF(TRIM(c.partai), '')) AS container_label,
    c.status AS container_status,
    cb.loaded_at,
    b.tanggal_masuk,
    b.updated_at AS barang_updated_at
FROM barangs b
INNER JOIN container_barangs cb ON cb.barang_id = b.id
INNER JOIN containers c ON c.id = cb.container_id
LEFT JOIN markings m ON m.code = TRIM(b.marking)
LEFT JOIN users u_barang ON u_barang.id = b.user_id
LEFT JOIN users u_marking ON u_marking.id = m.user_id
WHERE b.status = 'bongkar'
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
ORDER BY container_label, marking, projected_customer_label, b.id;

-- -----------------------------------------------------------------------------
-- C3. Hanya yang siap backfill (sama filter artisan tanpa --dry-run proyeksi)
--     Scope: --in-container
-- -----------------------------------------------------------------------------
SELECT
    b.id AS barang_id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.user_id,
    COALESCE(u.name, b.customer_name) AS customer_label,
    b.total_cbm,
    b.price_per_cbm,
    GROUP_CONCAT(
        DISTINCT COALESCE(NULLIF(TRIM(c.container_no), ''), NULLIF(TRIM(c.partai), ''))
        ORDER BY c.id SEPARATOR ', '
    ) AS containers,
    MAX(b.updated_at) AS barang_updated_at
FROM barangs b
INNER JOIN container_barangs cb ON cb.barang_id = b.id
INNER JOIN containers c ON c.id = cb.container_id
LEFT JOIN users u ON u.id = b.user_id
WHERE b.status = 'bongkar'
  AND b.user_id IS NOT NULL
  AND b.price_per_cbm > 0
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
GROUP BY
    b.id, b.no_index, b.marking, b.user_id, u.name, b.customer_name,
    b.total_cbm, b.price_per_cbm
ORDER BY b.id;

-- -----------------------------------------------------------------------------
-- C4. Agregat per container + marking (untuk prioritas backfill)
-- -----------------------------------------------------------------------------
SELECT
    c.id AS container_id,
    COALESCE(NULLIF(TRIM(c.container_no), ''), NULLIF(TRIM(c.partai), '')) AS container_label,
    c.status AS container_status,
    COALESCE(NULLIF(TRIM(b.marking), ''), '(tanpa marking)') AS marking,
    COUNT(DISTINCT b.id) AS barang_count,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND b.price_per_cbm > 0 THEN 1
        ELSE 0
    END) AS eligible_db_count,
    SUM(CASE WHEN b.user_id IS NULL THEN 1 ELSE 0 END) AS missing_user_count,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) <= 0 THEN 1
        ELSE 0
    END) AS zero_price_db_count,
    ROUND(SUM(b.total_cbm), 4) AS sum_total_cbm
FROM barangs b
INNER JOIN container_barangs cb ON cb.barang_id = b.id
INNER JOIN containers c ON c.id = cb.container_id
WHERE b.status = 'bongkar'
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
GROUP BY c.id, container_label, c.status, marking
ORDER BY barang_count DESC, container_label, marking;

-- -----------------------------------------------------------------------------
-- C5. Terblokir di DB tapi projected_eligible (user/harga dari marking)
-- -----------------------------------------------------------------------------
SELECT
    b.id AS barang_id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.user_id AS barang_user_id,
    m.user_id AS marking_user_id,
    b.price_per_cbm AS barang_price_per_cbm,
    (
        SELECT mp.volume_price
        FROM marking_pricings mp
        WHERE mp.marking_id = m.id
        ORDER BY mp.id DESC
        LIMIT 1
    ) AS marking_volume_price_fallback,
    CASE
        WHEN b.user_id IS NULL THEN 'no_customer'
        WHEN COALESCE(b.price_per_cbm, 0) <= 0 THEN 'no_price'
        ELSE NULL
    END AS db_block_reason,
    (
        SELECT GROUP_CONCAT(
            DISTINCT COALESCE(NULLIF(TRIM(c2.container_no), ''), NULLIF(TRIM(c2.partai), ''))
            ORDER BY c2.id SEPARATOR ', '
        )
        FROM container_barangs cb2
        INNER JOIN containers c2 ON c2.id = cb2.container_id
        WHERE cb2.barang_id = b.id
    ) AS containers
FROM barangs b
INNER JOIN container_barangs cb ON cb.barang_id = b.id
LEFT JOIN markings m ON m.code = TRIM(b.marking)
WHERE b.status = 'bongkar'
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
  AND NOT (b.user_id IS NOT NULL AND b.price_per_cbm > 0)
  AND COALESCE(b.user_id, m.user_id) IS NOT NULL
  AND GREATEST(
        COALESCE(b.price_per_cbm, 0),
        COALESCE((
            SELECT mp.volume_price
            FROM marking_pricings mp
            WHERE mp.marking_id = m.id
            ORDER BY mp.id DESC
            LIMIT 1
        ), 0)
    ) > 0
GROUP BY
    b.id, b.no_index, b.marking, b.user_id, m.user_id, m.id, b.price_per_cbm
ORDER BY b.id;

-- -----------------------------------------------------------------------------
-- C6. Draft invoice terbuka (konsolidasi) — barang container eligible
--     Sama ide query A6 di bongkar_missing_invoices.sql
-- -----------------------------------------------------------------------------
SELECT
    b.id AS barang_id,
    TRIM(b.marking) AS marking,
    b.user_id,
    inv.id AS open_invoice_id,
    inv.invoice_no,
    inv.status AS invoice_status,
    inv.grand_total,
    GROUP_CONCAT(
        DISTINCT COALESCE(NULLIF(TRIM(c.container_no), ''), NULLIF(TRIM(c.partai), ''))
        ORDER BY c.id SEPARATOR ', '
    ) AS containers
FROM barangs b
INNER JOIN container_barangs cb ON cb.barang_id = b.id
INNER JOIN containers c ON c.id = cb.container_id
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
GROUP BY b.id, b.marking, b.user_id, inv.id, inv.invoice_no, inv.status, inv.grand_total
ORDER BY b.id, inv.id;

-- -----------------------------------------------------------------------------
-- Contoh filter marking (ganti literal, tanpa variabel session)
-- -----------------------------------------------------------------------------
/*
SELECT ... FROM barangs b ...
WHERE b.status = 'bongkar'
  AND TRIM(b.marking) = 'EK/ON/SONDAKH'
  AND EXISTS (SELECT 1 FROM container_barangs cb WHERE cb.barang_id = b.id)
  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id);
*/

-- -----------------------------------------------------------------------------
-- Verifikasi setelah backfill
-- -----------------------------------------------------------------------------
-- C1 harus menunjukkan eligible_backfill_db_now = 0 (atau turun).
-- C3 harus kosong jika semua sudah punya invoice_items.
