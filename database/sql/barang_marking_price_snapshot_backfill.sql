-- =============================================================================
-- Backfill marking price snapshot on barangs from current marking master
-- =============================================================================
--
-- Purpose: Existing shipments should keep prices frozen on barangs.price_per_cbm,
--          weight_price, weight_threshold_kg. Run once to snapshot current marking
--          prices onto rows that are missing or need alignment.
--
-- Mirrors RMB rate snapshot: values live on barangs, not re-read from markings
-- on every save/display.
--
-- BEFORE UPDATE: run the SELECT preview (section 1) and review counts.
-- MySQL 8+. SET NAMES utf8mb4 recommended.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. PREVIEW — rows that will be updated (pricing row resolved per cbm_type)
-- -----------------------------------------------------------------------------
SELECT
    b.id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.cbm_type,
    mpi.name AS pricing_item_name,
    b.price_per_cbm AS old_price_per_cbm,
    mp.volume_price AS new_price_per_cbm,
    b.weight_price AS old_weight_price,
    mp.gw_price AS new_weight_price,
    b.weight_threshold_kg AS old_weight_threshold_kg,
    m.max_weight_kg AS new_weight_threshold_kg
FROM barangs b
INNER JOIN markings m ON m.code = TRIM(b.marking)
LEFT JOIN master_pricing_items mpi ON (
    (TRIM(COALESCE(b.cbm_type, '')) REGEXP '^[0-9]+$' AND mpi.id = CAST(TRIM(b.cbm_type) AS UNSIGNED))
    OR LOWER(REPLACE(TRIM(mpi.name), ' ', '')) = LOWER(REPLACE(TRIM(b.cbm_type), ' ', ''))
    OR (
        LOWER(REPLACE(TRIM(b.cbm_type), ' ', '')) IN ('cbm', 'piece', 'rim', 'ton', 'set')
        AND LOWER(REPLACE(TRIM(mpi.name), ' ', '')) = 'umum'
    )
)
LEFT JOIN marking_pricings mp ON mp.marking_id = m.id AND mp.pricing_item_id = mpi.id
WHERE TRIM(COALESCE(b.marking, '')) <> ''
ORDER BY b.id;

-- -----------------------------------------------------------------------------
-- 2. PREVIEW — fallback when cbm_type does not match any pricing item (uses latest row)
-- -----------------------------------------------------------------------------
SELECT
    b.id,
    b.no_index,
    TRIM(b.marking) AS marking,
    b.cbm_type,
    '(fallback latest)' AS pricing_item_name,
    b.price_per_cbm AS old_price_per_cbm,
    fb.volume_price AS new_price_per_cbm,
    b.weight_price AS old_weight_price,
    fb.gw_price AS new_weight_price,
    b.weight_threshold_kg AS old_weight_threshold_kg,
    m.max_weight_kg AS new_weight_threshold_kg
FROM barangs b
INNER JOIN markings m ON m.code = TRIM(b.marking)
INNER JOIN (
    SELECT mp1.marking_id, mp1.volume_price, mp1.gw_price
    FROM marking_pricings mp1
    INNER JOIN (
        SELECT marking_id, MAX(id) AS max_id
        FROM marking_pricings
        GROUP BY marking_id
    ) latest ON latest.max_id = mp1.id
) fb ON fb.marking_id = m.id
WHERE TRIM(COALESCE(b.marking, '')) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM master_pricing_items mpi2
      INNER JOIN marking_pricings mp2 ON mp2.pricing_item_id = mpi2.id AND mp2.marking_id = m.id
      WHERE (TRIM(COALESCE(b.cbm_type, '')) REGEXP '^[0-9]+$' AND mpi2.id = CAST(TRIM(b.cbm_type) AS UNSIGNED))
         OR LOWER(REPLACE(TRIM(mpi2.name), ' ', '')) = LOWER(REPLACE(TRIM(b.cbm_type), ' ', ''))
         OR (
             LOWER(REPLACE(TRIM(b.cbm_type), ' ', '')) IN ('cbm', 'piece', 'rim', 'ton', 'set')
             AND LOWER(REPLACE(TRIM(mpi2.name), ' ', '')) = 'umum'
         )
  )
ORDER BY b.id;

-- -----------------------------------------------------------------------------
-- 3. UPDATE — snapshot matched pricing row (primary path)
-- -----------------------------------------------------------------------------
UPDATE barangs b
INNER JOIN markings m ON m.code = TRIM(b.marking)
INNER JOIN master_pricing_items mpi ON (
    (TRIM(COALESCE(b.cbm_type, '')) REGEXP '^[0-9]+$' AND mpi.id = CAST(TRIM(b.cbm_type) AS UNSIGNED))
    OR LOWER(REPLACE(TRIM(mpi.name), ' ', '')) = LOWER(REPLACE(TRIM(b.cbm_type), ' ', ''))
    OR (
        LOWER(REPLACE(TRIM(b.cbm_type), ' ', '')) IN ('cbm', 'piece', 'rim', 'ton', 'set')
        AND LOWER(REPLACE(TRIM(mpi.name), ' ', '')) = 'umum'
    )
)
INNER JOIN marking_pricings mp ON mp.marking_id = m.id AND mp.pricing_item_id = mpi.id
SET
    b.price_per_cbm = mp.volume_price,
    b.weight_price = mp.gw_price,
    b.weight_threshold_kg = COALESCE(NULLIF(m.max_weight_kg, 0), b.weight_threshold_kg),
    b.updated_at = NOW()
WHERE TRIM(COALESCE(b.marking, '')) <> '';

-- -----------------------------------------------------------------------------
-- 4. UPDATE — fallback for barang still on zero price (latest marking_pricings row)
-- -----------------------------------------------------------------------------
UPDATE barangs b
INNER JOIN markings m ON m.code = TRIM(b.marking)
INNER JOIN (
    SELECT mp1.marking_id, mp1.volume_price, mp1.gw_price
    FROM marking_pricings mp1
    INNER JOIN (
        SELECT marking_id, MAX(id) AS max_id
        FROM marking_pricings
        GROUP BY marking_id
    ) latest ON latest.max_id = mp1.id
) fb ON fb.marking_id = m.id
SET
    b.price_per_cbm = fb.volume_price,
    b.weight_price = fb.gw_price,
    b.weight_threshold_kg = COALESCE(NULLIF(m.max_weight_kg, 0), b.weight_threshold_kg),
    b.updated_at = NOW()
WHERE TRIM(COALESCE(b.marking, '')) <> ''
  AND COALESCE(b.price_per_cbm, 0) = 0
  AND NOT EXISTS (
      SELECT 1
      FROM master_pricing_items mpi2
      INNER JOIN marking_pricings mp2 ON mp2.pricing_item_id = mpi2.id AND mp2.marking_id = m.id
      WHERE (TRIM(COALESCE(b.cbm_type, '')) REGEXP '^[0-9]+$' AND mpi2.id = CAST(TRIM(b.cbm_type) AS UNSIGNED))
         OR LOWER(REPLACE(TRIM(mpi2.name), ' ', '')) = LOWER(REPLACE(TRIM(b.cbm_type), ' ', ''))
         OR (
             LOWER(REPLACE(TRIM(b.cbm_type), ' ', '')) IN ('cbm', 'piece', 'rim', 'ton', 'set')
             AND LOWER(REPLACE(TRIM(mpi2.name), ' ', '')) = 'umum'
         )
  );

-- -----------------------------------------------------------------------------
-- 5. VERIFY — count barang with marking but zero CBM price after backfill
-- -----------------------------------------------------------------------------
SELECT COUNT(*) AS barang_marking_zero_price_after_backfill
FROM barangs b
LEFT JOIN markings m ON m.code = TRIM(b.marking)
WHERE TRIM(COALESCE(b.marking, '')) <> ''
  AND COALESCE(b.price_per_cbm, 0) = 0;
