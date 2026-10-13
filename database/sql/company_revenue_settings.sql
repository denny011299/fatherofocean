-- Keuntungan perusahaan per pengiriman (kas admin)
INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('company_revenue_fixed_amount', '150000', NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW();

-- Harga modal global (CBM / GW)
INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
    ('company_revenue_cost_volume_price', '0', NOW(), NOW()),
    ('company_revenue_cost_gw_price', '0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW();
