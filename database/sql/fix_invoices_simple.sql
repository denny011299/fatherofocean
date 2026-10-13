-- Invoice hilang + jatuh tempo (simple)
-- Belum bongkar = due NULL (—) | Sudah bongkar = due hari ini (telat 0)
-- Jalankan sekali di phpMyAdmin / Navicat

-- 1) Buat invoice draft utk barang yang belum punya invoice
INSERT INTO invoices (invoice_no, user_id, price_per_cbm, status, due_date, created_by, created_at, updated_at)
SELECT
    LEFT(CONCAT('INV-', REPLACE(TRIM(b.no_index), ' ', '')), 30),
    b.user_id,
    b.price_per_cbm,
    'draft',
    CASE WHEN b.status = 'bongkar' THEN CURDATE() ELSE NULL END,
    NULL,
    NOW(),
    NOW()
FROM barangs b
WHERE b.status IN ('stuffing', 'inbound', 'etd', 'eta', 'bongkar')
  AND b.user_id IS NOT NULL
  AND COALESCE(b.price_per_cbm, 0) > 0
  AND TRIM(IFNULL(b.no_index, '')) <> ''
  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id)
  AND NOT EXISTS (
      SELECT 1 FROM invoices i
      WHERE i.invoice_no = LEFT(CONCAT('INV-', REPLACE(TRIM(b.no_index), ' ', '')), 30)
  );

INSERT INTO invoice_items (invoice_id, barang_id, cbm, price_per_cbm, insurance, extra, line_total, created_at, updated_at)
SELECT
    i.id,
    b.id,
    COALESCE(b.total_cbm, 0),
    b.price_per_cbm,
    ROUND(COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100, 2),
    COALESCE(b.extra_charge, 0),
    ROUND(
        COALESCE(b.total_cbm, 0) * b.price_per_cbm
        + COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100
        + COALESCE(b.extra_charge, 0),
        2
    ),
    NOW(),
    NOW()
FROM barangs b
INNER JOIN invoices i
    ON i.invoice_no = LEFT(CONCAT('INV-', REPLACE(TRIM(b.no_index), ' ', '')), 30)
WHERE b.status IN ('stuffing', 'inbound', 'etd', 'eta', 'bongkar')
  AND b.user_id IS NOT NULL
  AND COALESCE(b.price_per_cbm, 0) > 0
  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id);

UPDATE invoices i
INNER JOIN (
    SELECT invoice_id,
           ROUND(SUM(cbm), 4) AS total_cbm,
           ROUND(SUM(line_total), 2) AS grand_total
    FROM invoice_items
    GROUP BY invoice_id
) x ON x.invoice_id = i.id
SET i.total_cbm = x.total_cbm,
    i.subtotal = x.grand_total,
    i.grand_total = x.grand_total,
    i.updated_at = NOW()
WHERE i.status = 'draft';

-- 2) Rapikan jatuh tempo
UPDATE invoices i
SET i.due_date = NULL, i.updated_at = NOW()
WHERE i.status IN ('draft', 'sent')
  AND i.due_date IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii
      INNER JOIN barangs b ON b.id = ii.barang_id
      WHERE ii.invoice_id = i.id AND b.status = 'bongkar'
  );

UPDATE invoices i
SET i.due_date = CURDATE(), i.updated_at = NOW()
WHERE i.status IN ('draft', 'sent')
  AND i.due_date IS NULL
  AND EXISTS (
      SELECT 1 FROM invoice_items ii
      INNER JOIN barangs b ON b.id = ii.barang_id
      WHERE ii.invoice_id = i.id AND b.status = 'bongkar'
  );
