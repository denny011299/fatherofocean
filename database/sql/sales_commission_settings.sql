-- Komisi sales umum 2,5% (system_settings)
INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
    ('sales_commission_rate_default', '2.5', NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = '2.5', `updated_at` = NOW();
