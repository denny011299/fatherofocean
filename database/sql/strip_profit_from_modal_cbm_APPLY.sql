-- Modal CBM - 150000 (supaya tidak double dengan profit)
-- Jalankan di phpMyAdmin / Navicat

-- 1) Master Sistem (Harga Modal per Tipe Barang)
UPDATE company_revenue_item_modals
SET cost_volume_price = cost_volume_price - 150000,
    updated_at = NOW()
WHERE cost_volume_price >= 150000;

-- 2) Snapshot modal di pengiriman
UPDATE barangs
SET cost_price_per_cbm = cost_price_per_cbm - 150000,
    updated_at = NOW()
WHERE cost_price_per_cbm IS NOT NULL
  AND cost_price_per_cbm >= 150000;
