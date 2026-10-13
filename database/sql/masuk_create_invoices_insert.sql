-- =============================================================================
-- Buat tagihan (invoices + invoice_items) untuk barang status masuk barang tanpa invoice
-- =============================================================================
--
-- TRADEOFF (baca sebelum jalankan):
--   • Mode SIMPLE (Part B1): satu invoice draft per barang — SQL paling mudah,
--     tapi bisa beda dengan aplikasi yang menggabungkan beberapa no_index /
--     scope container+marking ke satu invoice.
--   • Mode GROUPED (Part B2, disarankan): satu invoice per
--     user_id + marking_key + container_scope — lebih dekat ke
--     InvoiceConsolidation::scopeKey(), tetap tidak 100% sama dengan PHP
--     (konsolidasi ke draft terbuka, split scope, aturan berat/CBM kompleks).
--   • line_total di SQL memakai perkiraan CBM×harga + asuransi + extra
--     (GREATEST(cbm, minimum_charge marking)). Jika barang pakai aturan
--     weight_split / weight, nominal bisa beda — setelah insert jalankan
--     `php artisan invoices:recalculate` atau buka invoice di UI finance.
--   • Nomor invoice: INV-{no_index} atau INV-{a}+{b}+... (sorted, spasi dihapus),
--     selaras Invoice::formatInvoiceNumber(). Jika bentrok dengan invoice lama,
--     suffix -2, -3 ditambahkan otomatis (mirip ensureUniqueInvoiceNo).
--
-- PERINGATAN:
--   1. BACKUP database (mysqldump) sebelum menjalankan Part B.
--   2. Uji di STAGING; jangan langsung di produksi.
--   3. Bungkus Part B dalam TRANSACTION; ROLLBACK jika Part D tidak OK.
--   4. Script ini TIDAK mengubah invoice yang sudah punya invoice_items.
--   5. Jika sudah ada invoice draft/sent terbuka untuk scope sama, aplikasi
--      biasanya menambah item ke situ — SQL INSERT membuat invoice BARU.
--      Cek query A6 di masuk_missing_invoices.sql
--
-- CARA PAKAI TANPA TERMINAL (phpMyAdmin / Navicat / HeidiSQL):
--   1. BACKUP database dulu (Export).
--   2. Buka tab SQL, jalankan Part A (query A1–A3) — hanya SELECT, aman.
--   3. Jika ada eligible, buka blok Part B2 (hapus /* di awal dan */ di akhir B2).
--   4. Jalankan dari START TRANSACTION sampai sebelum COMMIT.
--   5. Jalankan blok "Recalculate header" (hapus komentar /* */).
--   6. Jalankan Part D (D1–D3). Jika OK → COMMIT; jika salah → ROLLBACK.
--   7. Barang belum dimuat container → partai invoice tampil "-" (normal).
--
-- Mode SIMPLE (B1): lebih mudah tapi satu invoice per barang (tidak digabung marking).
-- Mode GROUPED (B2): disarankan — gabung per customer + marking (partai "_" = belum dimuat).
--
-- =============================================================================

SET NAMES utf8mb4;
SET @run_started_at := NOW(6);

-- -----------------------------------------------------------------------------
-- Part A — Safety checks (SELECT saja, aman dijalankan kapan saja)
-- -----------------------------------------------------------------------------

-- A1. Ringkasan eligible vs terblokir
SELECT
    COUNT(*) AS total_masuk_tanpa_invoice,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND b.price_per_cbm > 0 THEN 1
        ELSE 0
    END) AS eligible_insert,
    SUM(CASE WHEN b.user_id IS NULL THEN 1 ELSE 0 END) AS blocked_missing_user,
    SUM(CASE
        WHEN b.user_id IS NOT NULL AND COALESCE(b.price_per_cbm, 0) <= 0 THEN 1
        ELSE 0
    END) AS blocked_price_per_cbm
FROM barangs b
WHERE b.status IN ('stuffing','inbound','etd','eta','bongkar')
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  );

-- A2. Kandidat invoice_no yang bentrok dengan invoices yang sudah ada (preview)
SELECT
    e.barang_id,
    e.no_index,
    e.candidate_invoice_no,
    EXISTS (
        SELECT 1 FROM invoices i WHERE i.invoice_no = e.candidate_invoice_no
    ) AS already_exists
FROM (
    SELECT
        b.id AS barang_id,
        b.no_index,
        CONCAT(
            'INV-',
            REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), '')
        ) AS candidate_invoice_no
    FROM barangs b
    WHERE b.status IN ('stuffing','inbound','etd','eta','bongkar')
      AND b.user_id IS NOT NULL
      AND b.price_per_cbm > 0
      AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id)
) e
WHERE EXISTS (
    SELECT 1 FROM invoices i WHERE i.invoice_no = e.candidate_invoice_no
);

-- A3. Daftar eligible (sama filter Barang::createInvoiceIfNeeded)
SELECT
    b.id AS barang_id,
    b.no_index,
    b.marking,
    b.user_id,
    b.total_cbm,
    b.price_per_cbm,
    b.insurance_value,
    b.insurance_rate,
    b.extra_charge,
    ROUND(
        COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100,
        2
    ) AS insurance_amount,
    ROUND(
        GREATEST(
            COALESCE(b.total_cbm, 0),
            COALESCE(mk.minimum_charge_cbm, 0)
        ) * b.price_per_cbm
        + COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100
        + COALESCE(b.extra_charge, 0),
        2
    ) AS approx_line_total
FROM barangs b
LEFT JOIN (
    SELECT m.code, COALESCE(m.minimum_charge, 0) AS minimum_charge_cbm
    FROM markings m
) mk ON mk.code = TRIM(b.marking)
WHERE b.status IN ('stuffing','inbound','etd','eta','bongkar')
  AND b.user_id IS NOT NULL
  AND b.price_per_cbm > 0
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  )
ORDER BY b.id;

-- =============================================================================
-- Part B — INSERT (pilih SATU mode: B1 ATAU B2, jangan keduanya)
-- =============================================================================
--
-- START TRANSACTION;
-- ... jalankan blok B1 ATAU B2 ...
-- ... jalankan "Recalculate header" ...
-- ... Part D ...
-- COMMIT;   -- atau ROLLBACK;

-- ---------------------------------------------------------------------------
-- Helper view logic (inline di CTE) — marking_key + container_scope
--   marking_key: markings.code jika cocok, else trim(marking), else __no_marking__
--   container_scope: GROUP_CONCAT(container_id) atau '_'
--   scope_key = CONCAT(marking_key, '|', container_scope)
-- ---------------------------------------------------------------------------

-- ===========================================================================
-- Part B1 — SIMPLE: satu invoice per barang
-- ===========================================================================
/*
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_masuk_simple;
CREATE TEMPORARY TABLE tmp_masuk_simple (
    barang_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    price_per_cbm DECIMAL(12, 2) NOT NULL,
    no_index_clean VARCHAR(50) NOT NULL,
    candidate_invoice_no VARCHAR(500) NOT NULL,
    final_invoice_no VARCHAR(500) NOT NULL,
    due_date DATE NULL,
    cbm DECIMAL(10, 4) NOT NULL,
    insurance DECIMAL(12, 2) NOT NULL,
    extra DECIMAL(12, 2) NOT NULL,
    line_total DECIMAL(15, 2) NOT NULL,
    invoice_id BIGINT UNSIGNED NULL,
    KEY (final_invoice_no),
    KEY (user_id)
);

INSERT INTO tmp_masuk_simple (
    barang_id, user_id, price_per_cbm, no_index_clean,
    candidate_invoice_no, final_invoice_no,
    due_date, cbm, insurance, extra, line_total
)
SELECT
    b.id,
    b.user_id,
    b.price_per_cbm,
    REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), '') AS no_index_clean,
    CONCAT('INV-', REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), '')) AS candidate_invoice_no,
    CONCAT('INV-', REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), '')) AS final_invoice_no,
    CASE
        WHEN COALESCE(m.invoice_due_days, 0) > 0
            THEN DATE_ADD(CURDATE(), INTERVAL m.invoice_due_days DAY)
        WHEN COALESCE(mp.max_due_days, 0) > 0
            THEN DATE_ADD(CURDATE(), INTERVAL mp.max_due_days DAY)
        ELSE CURDATE()
    END AS due_date,
    COALESCE(b.total_cbm, 0) AS cbm,
    ROUND(COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100, 2) AS insurance,
    COALESCE(b.extra_charge, 0) AS extra,
    ROUND(
        GREATEST(COALESCE(b.total_cbm, 0), COALESCE(m.minimum_charge, 0)) * b.price_per_cbm
        + COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100
        + COALESCE(b.extra_charge, 0),
        2
    ) AS line_total
FROM barangs b
LEFT JOIN markings m ON m.code = TRIM(b.marking)
LEFT JOIN (
    SELECT marking_id, MAX(invoice_due_days) AS max_due_days
    FROM marking_pricings
    GROUP BY marking_id
) mp ON mp.marking_id = m.id
WHERE b.status IN ('stuffing','inbound','etd','eta','bongkar')
  AND b.user_id IS NOT NULL
  AND b.price_per_cbm > 0
  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id)
  AND REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), '') <> '';

-- Unikkan invoice_no jika sudah dipakai (suffix -2, -3, ...)
UPDATE tmp_masuk_simple t
INNER JOIN (
    SELECT
        t2.barang_id,
        CASE
            WHEN NOT EXISTS (
                SELECT 1 FROM invoices i WHERE i.invoice_no = t2.candidate_invoice_no
            )
            AND NOT EXISTS (
                SELECT 1 FROM tmp_masuk_simple o
                WHERE o.barang_id < t2.barang_id
                  AND o.candidate_invoice_no = t2.candidate_invoice_no
            )
                THEN t2.candidate_invoice_no
            ELSE CONCAT(
                LEFT(t2.candidate_invoice_no, 485),
                '-',
                t2.barang_id
            )
        END AS resolved_no
    FROM tmp_masuk_simple t2
) x ON x.barang_id = t.barang_id
SET t.final_invoice_no = x.resolved_no;

INSERT INTO invoices (
    invoice_no, user_id, total_cbm, price_per_cbm,
    subtotal, insurance_total, extra_charges,
    promo_discount, claim_deduction_total, grand_total,
    status, due_date, created_by, created_at, updated_at
)
SELECT
    t.final_invoice_no,
    t.user_id,
    t.cbm,
    t.price_per_cbm,
    GREATEST(0, t.line_total - t.insurance - t.extra),
    t.insurance,
    t.extra,
    0, 0,
    t.line_total,
    'draft',
    t.due_date,
    NULL,
    @run_started_at,
    @run_started_at
FROM tmp_masuk_simple t;

UPDATE tmp_masuk_simple t
INNER JOIN invoices i
    ON i.invoice_no = t.final_invoice_no
   AND i.user_id = t.user_id
   AND i.created_at = @run_started_at
SET t.invoice_id = i.id;

INSERT INTO invoice_items (
    invoice_id, barang_id, cbm, price_per_cbm,
    insurance, extra, line_total, created_at, updated_at
)
SELECT
    t.invoice_id,
    t.barang_id,
    t.cbm,
    t.price_per_cbm,
    t.insurance,
    t.extra,
    t.line_total,
    @run_started_at,
    @run_started_at
FROM tmp_masuk_simple t
WHERE t.invoice_id IS NOT NULL;

DROP TEMPORARY TABLE IF EXISTS tmp_masuk_simple;

-- COMMIT;
*/

-- ===========================================================================
-- Part B2 — GROUPED: satu invoice per user + marking_key + container_scope
-- (default disarankan; hapus komentar blok di bawah untuk menjalankan)
-- ===========================================================================

/*
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_masuk_eligible;
CREATE TEMPORARY TABLE tmp_masuk_eligible (
    barang_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    scope_key VARCHAR(255) NOT NULL,
    marking_key VARCHAR(120) NOT NULL,
    no_index_clean VARCHAR(50) NOT NULL,
    price_per_cbm DECIMAL(12, 2) NOT NULL,
    due_date DATE NULL,
    cbm DECIMAL(10, 4) NOT NULL,
    insurance DECIMAL(12, 2) NOT NULL,
    extra DECIMAL(12, 2) NOT NULL,
    line_total DECIMAL(15, 2) NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NULL,
    final_invoice_no VARCHAR(500) NULL,
    KEY (group_id),
    KEY (scope_key),
    KEY (user_id)
);

INSERT INTO tmp_masuk_eligible (
    barang_id, user_id, scope_key, marking_key, no_index_clean,
    price_per_cbm, due_date, cbm, insurance, extra, line_total, group_id
)
SELECT
    src.barang_id,
    src.user_id,
    src.scope_key,
    src.marking_key,
    src.no_index_clean,
    src.price_per_cbm,
    src.due_date,
    src.cbm,
    src.insurance,
    src.extra,
    src.line_total,
    DENSE_RANK() OVER (
        ORDER BY src.user_id, src.scope_key
    ) AS group_id
FROM (
    SELECT
        b.id AS barang_id,
        b.user_id,
        CONCAT(
            CASE
                WHEN NULLIF(TRIM(b.marking), '') IS NULL THEN '__no_marking__'
                WHEN m.id IS NOT NULL THEN TRIM(m.code)
                ELSE TRIM(b.marking)
            END,
            '|',
            COALESCE((
                SELECT GROUP_CONCAT(cb.container_id ORDER BY cb.container_id SEPARATOR ',')
                FROM container_barangs cb
                WHERE cb.barang_id = b.id
            ), '_')
        ) AS scope_key,
        CASE
            WHEN NULLIF(TRIM(b.marking), '') IS NULL THEN '__no_marking__'
            WHEN m.id IS NOT NULL THEN TRIM(m.code)
            ELSE TRIM(b.marking)
        END AS marking_key,
        REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), '') AS no_index_clean,
        b.price_per_cbm,
        CASE
            WHEN COALESCE(m.invoice_due_days, 0) > 0
                THEN DATE_ADD(CURDATE(), INTERVAL m.invoice_due_days DAY)
            WHEN COALESCE(mp.max_due_days, 0) > 0
                THEN DATE_ADD(CURDATE(), INTERVAL mp.max_due_days DAY)
            ELSE CURDATE()
        END AS due_date,
        COALESCE(b.total_cbm, 0) AS cbm,
        ROUND(COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100, 2) AS insurance,
        COALESCE(b.extra_charge, 0) AS extra,
        ROUND(
            GREATEST(COALESCE(b.total_cbm, 0), COALESCE(m.minimum_charge, 0)) * b.price_per_cbm
            + COALESCE(b.insurance_value, 0) * COALESCE(b.insurance_rate, 0) / 100
            + COALESCE(b.extra_charge, 0),
            2
        ) AS line_total
    FROM barangs b
    LEFT JOIN markings m ON m.code = TRIM(b.marking)
    LEFT JOIN (
        SELECT marking_id, MAX(invoice_due_days) AS max_due_days
        FROM marking_pricings
        GROUP BY marking_id
    ) mp ON mp.marking_id = m.id
    WHERE b.status IN ('stuffing','inbound','etd','eta','bongkar')
      AND b.user_id IS NOT NULL
      AND b.price_per_cbm > 0
      AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id)
      AND REPLACE(REPLACE(TRIM(b.no_index), ' ', ''), CHAR(9), '') <> ''
) src;

DROP TEMPORARY TABLE IF EXISTS tmp_masuk_groups;
CREATE TEMPORARY TABLE tmp_masuk_groups (
    group_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    scope_key VARCHAR(255) NOT NULL,
    price_per_cbm DECIMAL(12, 2) NOT NULL,
    due_date DATE NULL,
    candidate_invoice_no VARCHAR(500) NOT NULL,
    final_invoice_no VARCHAR(500) NOT NULL,
    total_cbm DECIMAL(10, 4) NOT NULL,
    insurance_total DECIMAL(15, 2) NOT NULL,
    extra_charges DECIMAL(15, 2) NOT NULL,
    subtotal DECIMAL(15, 2) NOT NULL,
    grand_total DECIMAL(15, 2) NOT NULL,
    invoice_id BIGINT UNSIGNED NULL
);

INSERT INTO tmp_masuk_groups (
    group_id, user_id, scope_key, price_per_cbm, due_date,
    candidate_invoice_no, final_invoice_no,
    total_cbm, insurance_total, extra_charges, subtotal, grand_total
)
SELECT
    e.group_id,
    e.user_id,
    MIN(e.scope_key),
    MAX(e.price_per_cbm),
    MIN(e.due_date),
    CONCAT(
        'INV-',
        (
            SELECT GROUP_CONCAT(x.no_index_clean ORDER BY x.no_index_clean SEPARATOR '+')
            FROM tmp_masuk_eligible x
            WHERE x.group_id = e.group_id
        )
    ),
    CONCAT(
        'INV-',
        (
            SELECT GROUP_CONCAT(x.no_index_clean ORDER BY x.no_index_clean SEPARATOR '+')
            FROM tmp_masuk_eligible x
            WHERE x.group_id = e.group_id
        )
    ),
    SUM(e.cbm),
    SUM(e.insurance),
    SUM(e.extra),
    SUM(GREATEST(0, e.line_total - e.insurance - e.extra)),
    SUM(e.line_total)
FROM tmp_masuk_eligible e
GROUP BY e.group_id, e.user_id;

-- Format panjang: INV-{first}+{N}pengiriman (selaras formatInvoiceNumber)
UPDATE tmp_masuk_groups g
SET
    candidate_invoice_no = IF(
        CHAR_LENGTH(g.candidate_invoice_no) > 250,
        CONCAT(
            'INV-',
            SUBSTRING_INDEX(SUBSTRING(g.candidate_invoice_no, 5), '+', 1),
            '+',
            (SELECT COUNT(*) - 1 FROM tmp_masuk_eligible e WHERE e.group_id = g.group_id),
            'pengiriman'
        ),
        g.candidate_invoice_no
    ),
    final_invoice_no = IF(
        CHAR_LENGTH(g.candidate_invoice_no) > 250,
        CONCAT(
            'INV-',
            SUBSTRING_INDEX(SUBSTRING(g.candidate_invoice_no, 5), '+', 1),
            '+',
            (SELECT COUNT(*) - 1 FROM tmp_masuk_eligible e WHERE e.group_id = g.group_id),
            'pengiriman'
        ),
        g.candidate_invoice_no
    );

UPDATE tmp_masuk_groups g
SET final_invoice_no = CASE
    WHEN NOT EXISTS (SELECT 1 FROM invoices i WHERE i.invoice_no = g.candidate_invoice_no)
        THEN g.candidate_invoice_no
    ELSE CONCAT(LEFT(g.candidate_invoice_no, 485), '-', g.group_id)
END;

INSERT INTO invoices (
    invoice_no, user_id, total_cbm, price_per_cbm,
    subtotal, insurance_total, extra_charges,
    promo_discount, claim_deduction_total, grand_total,
    status, due_date, created_by, created_at, updated_at
)
SELECT
    g.final_invoice_no,
    g.user_id,
    g.total_cbm,
    g.price_per_cbm,
    g.subtotal,
    g.insurance_total,
    g.extra_charges,
    0, 0,
    g.grand_total,
    'draft',
    g.due_date,
    NULL,
    @run_started_at,
    @run_started_at
FROM tmp_masuk_groups g;

UPDATE tmp_masuk_groups g
INNER JOIN invoices i
    ON i.invoice_no = g.final_invoice_no
   AND i.user_id = g.user_id
   AND i.created_at = @run_started_at
SET g.invoice_id = i.id;

UPDATE tmp_masuk_eligible e
INNER JOIN tmp_masuk_groups g ON g.group_id = e.group_id
SET e.invoice_id = g.invoice_id,
    e.final_invoice_no = g.final_invoice_no;

INSERT INTO invoice_items (
    invoice_id, barang_id, cbm, price_per_cbm,
    insurance, extra, line_total, created_at, updated_at
)
SELECT
    e.invoice_id,
    e.barang_id,
    e.cbm,
    e.price_per_cbm,
    e.insurance,
    e.extra,
    e.line_total,
    @run_started_at,
    @run_started_at
FROM tmp_masuk_eligible e
WHERE e.invoice_id IS NOT NULL;

DROP TEMPORARY TABLE IF EXISTS tmp_masuk_eligible;
DROP TEMPORARY TABLE IF EXISTS tmp_masuk_groups;

-- COMMIT;
*/

-- -----------------------------------------------------------------------------
-- Recalculate header dari invoice_items (+ biaya tambahan barang) untuk
-- invoice yang baru dibuat pada run ini. Jalankan setelah B1 atau B2.
-- -----------------------------------------------------------------------------
/*
UPDATE invoices i
INNER JOIN (
    SELECT
        ii.invoice_id,
        ROUND(SUM(ii.cbm), 4) AS total_cbm,
        ROUND(SUM(GREATEST(0, ii.line_total - ii.insurance - ii.extra)), 2) AS subtotal,
        ROUND(SUM(ii.insurance), 2) AS insurance_total,
        ROUND(SUM(ii.extra), 2) AS extra_charges,
        ROUND(SUM(ii.line_total), 2) AS items_line_sum
    FROM invoice_items ii
    GROUP BY ii.invoice_id
) agg ON agg.invoice_id = i.id
LEFT JOIN (
    SELECT
        ii.invoice_id,
        ROUND(COALESCE(SUM(bap.price), 0), 2) AS additional_total
    FROM invoice_items ii
    INNER JOIN barang_additional_prices bap ON bap.barang_id = ii.barang_id
    GROUP BY ii.invoice_id
) addl ON addl.invoice_id = i.id
SET
    i.total_cbm = agg.total_cbm,
    i.subtotal = agg.subtotal,
    i.insurance_total = agg.insurance_total,
    i.extra_charges = agg.extra_charges,
    i.grand_total = ROUND(
        agg.items_line_sum + COALESCE(addl.additional_total, 0)
        - COALESCE(i.promo_discount, 0)
        - COALESCE(i.claim_deduction_total, 0),
        2
    ),
    i.updated_at = NOW(6)
WHERE i.status = 'draft'
  AND i.created_at >= @run_started_at;
*/

-- -----------------------------------------------------------------------------
-- Part C — Opsional: perbaiki data blocking (UPDATE; sesuaikan ID/nilai)
-- -----------------------------------------------------------------------------
-- Setelah UPDATE di bawah, jalankan ulang Part A lalu Part B.

-- UPDATE barangs
-- SET price_per_cbm = 650000.00,
--     updated_at = NOW()
-- WHERE id = 12345
--   AND status IN ('stuffing','inbound','etd','eta','bongkar')
--   AND user_id IS NOT NULL
--   AND COALESCE(price_per_cbm, 0) <= 0
--   AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE barang_id = barangs.id);

-- UPDATE barangs
-- SET user_id = 999,
--     updated_at = NOW()
-- WHERE id = 12345
--   AND status IN ('stuffing','inbound','etd','eta','bongkar')
--   AND user_id IS NULL
--   AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE barang_id = barangs.id);

-- -----------------------------------------------------------------------------
-- Part D — Verifikasi setelah COMMIT Part B
-- -----------------------------------------------------------------------------

-- D1. Masih ada masuk barang eligible tanpa invoice?
SELECT COUNT(*) AS remaining_eligible
FROM barangs b
WHERE b.status IN ('stuffing','inbound','etd','eta','bongkar')
  AND b.user_id IS NOT NULL
  AND b.price_per_cbm > 0
  AND NOT EXISTS (
      SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id
  );

-- D2. Invoice draft baru pada run terakhir (@run_started_at)
SELECT
    i.id,
    i.invoice_no,
    i.user_id,
    i.status,
    i.grand_total,
    COUNT(ii.id) AS item_count,
    GROUP_CONCAT(b.no_index ORDER BY b.no_index SEPARATOR ', ') AS no_indexes
FROM invoices i
INNER JOIN invoice_items ii ON ii.invoice_id = i.id
INNER JOIN barangs b ON b.id = ii.barang_id
WHERE i.created_at >= @run_started_at
GROUP BY i.id, i.invoice_no, i.user_id, i.status, i.grand_total
ORDER BY i.id;

-- D3. Barang masuk yang masih tanpa invoice_items (harus 0 jika backfill lengkap)
SELECT b.id, b.no_index, b.marking, b.user_id, b.price_per_cbm
FROM barangs b
WHERE b.status IN ('stuffing','inbound','etd','eta','bongkar')
  AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.barang_id = b.id)
ORDER BY b.id
LIMIT 50;
