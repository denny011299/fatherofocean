-- INSERT tagihan bongkar tanpa invoice — BACKUP dulu, jalankan utuh, COMMIT di akhir.
-- Contoh: barang 83 (1704.05), 85 (2004.05) EK/ON/SONDAKH → INV-1704.05, INV-2004.05.
SET NAMES utf8mb4;
SET @run_at := NOW();
START TRANSACTION;

-- 1) Perbaiki user_id ke portal customer (markings.user_id, mis. 55 YENNY)
UPDATE barangs b
INNER JOIN markings m ON TRIM(m.code) = TRIM(b.marking) AND m.user_id IS NOT NULL
SET b.user_id = m.user_id, b.updated_at = @run_at
WHERE b.status = 'bongkar'
  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id)
  AND (b.user_id IS NULL OR b.user_id <> m.user_id);

-- 2–4) Semua bongkar eligible: satu invoice draft per barang (CBM×harga + asuransi + extra)
DROP TEMPORARY TABLE IF EXISTS tmp_tagihan;
CREATE TEMPORARY TABLE tmp_tagihan (
    barang_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    invoice_no VARCHAR(500) NOT NULL,
    price_per_cbm DECIMAL(12, 2) NOT NULL,
    due_date DATE NULL,
    cbm DECIMAL(10, 4) NOT NULL,
    insurance DECIMAL(12, 2) NOT NULL,
    extra DECIMAL(12, 2) NOT NULL,
    line_total DECIMAL(15, 2) NOT NULL,
    invoice_id BIGINT UNSIGNED NULL,
    KEY (invoice_no)
);

INSERT INTO tmp_tagihan (
    barang_id, user_id, invoice_no, price_per_cbm,
    due_date, cbm, insurance, extra, line_total
)
SELECT
    b.id, b.user_id,
    CONCAT('INV-', REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), '')),
    b.price_per_cbm,
    CASE WHEN COALESCE(m.invoice_due_days, 0) > 0
        THEN DATE_ADD(CURDATE(), INTERVAL m.invoice_due_days DAY) ELSE CURDATE() END,
    COALESCE(b.total_cbm, 0),
    ROUND(COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100, 2),
    COALESCE(b.extra_charge, 0),
    ROUND(
        COALESCE(b.total_cbm, 0) * b.price_per_cbm
        + COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100
        + COALESCE(b.extra_charge, 0), 2)
FROM barangs b
LEFT JOIN markings m ON m.code = TRIM(b.marking)
WHERE b.status = 'bongkar'
  AND b.user_id IS NOT NULL AND b.price_per_cbm > 0
  AND TRIM(COALESCE(b.no_index, '')) <> ''
  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id)
  AND NOT EXISTS (
      SELECT 1 FROM invoices i
      WHERE i.invoice_no = CONCAT('INV-', REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), ''))
  );

INSERT INTO invoices (
    invoice_no, user_id, total_cbm, price_per_cbm,
    subtotal, insurance_total, extra_charges,
    promo_discount, claim_deduction_total, grand_total,
    status, due_date, created_by, created_at, updated_at
)
SELECT
    t.invoice_no, t.user_id, t.cbm, t.price_per_cbm,
    GREATEST(0, t.line_total - t.insurance - t.extra),
    t.insurance, t.extra, 0, 0, t.line_total,
    'draft', t.due_date, NULL, @run_at, @run_at
FROM tmp_tagihan t;

UPDATE tmp_tagihan t
INNER JOIN invoices i
    ON i.invoice_no = t.invoice_no AND i.user_id = t.user_id AND i.created_at = @run_at
SET t.invoice_id = i.id;

INSERT INTO invoice_items (
    invoice_id, barang_id, cbm, price_per_cbm,
    insurance, extra, line_total, created_at, updated_at
)
SELECT
    t.invoice_id, t.barang_id, t.cbm, t.price_per_cbm,
    t.insurance, t.extra, t.line_total, @run_at, @run_at
FROM tmp_tagihan t WHERE t.invoice_id IS NOT NULL;

UPDATE invoices i
INNER JOIN (
    SELECT ii.invoice_id,
        SUM(ii.cbm) AS total_cbm, MAX(ii.price_per_cbm) AS price_per_cbm,
        SUM(GREATEST(0, ii.line_total - ii.insurance - ii.extra)) AS subtotal,
        SUM(ii.insurance) AS insurance_total, SUM(ii.extra) AS extra_charges,
        SUM(ii.line_total) AS grand_total
    FROM invoice_items ii
    INNER JOIN tmp_tagihan t ON t.invoice_id = ii.invoice_id
    GROUP BY ii.invoice_id
) agg ON agg.invoice_id = i.id
SET i.total_cbm = agg.total_cbm, i.price_per_cbm = agg.price_per_cbm,
    i.subtotal = agg.subtotal, i.insurance_total = agg.insurance_total,
    i.extra_charges = agg.extra_charges, i.grand_total = agg.grand_total,
    i.updated_at = @run_at;

DROP TEMPORARY TABLE IF EXISTS tmp_tagihan;

SELECT COUNT(*) AS invoice_baru FROM invoices WHERE created_at = @run_at;
SELECT COUNT(*) AS item_baru FROM invoice_items WHERE created_at = @run_at;
SELECT b.id, b.no_index, i.invoice_no, i.user_id, i.grand_total, ii.line_total
FROM barangs b
LEFT JOIN invoice_items ii ON ii.barang_id = b.id
LEFT JOIN invoices i ON i.id = ii.invoice_id
WHERE b.id IN (83, 85) OR b.no_index IN ('1704.05', '2004.05');

COMMIT;
