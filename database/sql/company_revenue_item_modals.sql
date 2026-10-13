-- Harga modal per tipe barang (master_pricing_items)
-- Jalankan setelah migration 2026_05_30_120000_create_company_revenue_item_modals_table.php

-- Contoh: Umum (pricing_item_id = 1) — sesuaikan ID dari master_pricing_items
INSERT INTO `company_revenue_item_modals` (`pricing_item_id`, `cost_volume_price`, `cost_gw_price`, `created_at`, `updated_at`)
SELECT `id`, 575000, 5500, NOW(), NOW()
FROM `master_pricing_items`
WHERE LOWER(TRIM(`name`)) = 'umum'
ON DUPLICATE KEY UPDATE
    `cost_volume_price` = VALUES(`cost_volume_price`),
    `cost_gw_price` = VALUES(`cost_gw_price`),
    `updated_at` = NOW();

-- Contoh: Lartas
INSERT INTO `company_revenue_item_modals` (`pricing_item_id`, `cost_volume_price`, `cost_gw_price`, `created_at`, `updated_at`)
SELECT `id`, 620000, 6000, NOW(), NOW()
FROM `master_pricing_items`
WHERE LOWER(TRIM(`name`)) LIKE '%lartas%'
ON DUPLICATE KEY UPDATE
    `cost_volume_price` = VALUES(`cost_volume_price`),
    `cost_gw_price` = VALUES(`cost_gw_price`),
    `updated_at` = NOW();
