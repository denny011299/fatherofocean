-- Snapshot modal lama yang terlalu kecil (mis. 575000 - 150000 = 425000)
-- biar export Omset pakai master Sistem per kategori lagi.
--
-- Preview dulu:
SELECT id, no_index, marking, cbm_type, cost_price_per_cbm, cost_weight_price
FROM barangs
WHERE cost_price_per_cbm IS NOT NULL
  AND cost_price_per_cbm > 0
  AND cost_price_per_cbm < 1000000
ORDER BY cost_price_per_cbm, id;

-- Kalau yakin, kosongkan snapshot biar fallback ke master kategori:
UPDATE barangs
SET cost_price_per_cbm = NULL,
    cost_weight_price = NULL,
    updated_at = NOW()
WHERE cost_price_per_cbm IS NOT NULL
  AND cost_price_per_cbm > 0
  AND cost_price_per_cbm < 1000000;
