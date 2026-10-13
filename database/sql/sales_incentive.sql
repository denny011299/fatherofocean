-- Insentif sales — tier master + agregat per bulan (idempotent)
-- Jalankan setelah migrate atau standalone di production.

CREATE TABLE IF NOT EXISTS `sales_incentive_tiers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `pricing_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `cbm_min` DECIMAL(12,4) NULL DEFAULT NULL,
    `cbm_max` DECIMAL(12,4) NULL DEFAULT NULL,
    `cbm_per_block` DECIMAL(12,4) NOT NULL,
    `amount_per_block` DECIMAL(15,2) NOT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `sales_incentive_tiers_pricing_item_id_sort_order_index` (`pricing_item_id`, `sort_order`),
    CONSTRAINT `sales_incentive_tiers_pricing_item_id_foreign`
        FOREIGN KEY (`pricing_item_id`) REFERENCES `master_pricing_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_incentive_lines` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sales_id` BIGINT UNSIGNED NOT NULL,
    `barang_id` BIGINT UNSIGNED NOT NULL,
    `pricing_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `period` CHAR(7) NOT NULL COMMENT 'YYYY-MM bulan stuffing',
    `total_cbm` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `paid_cbm` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `stuffed_at` TIMESTAMP NULL DEFAULT NULL,
    `invoice_paid_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sales_incentive_lines_barang_id_unique` (`barang_id`),
    KEY `sales_incentive_lines_sales_id_period_index` (`sales_id`, `period`),
    KEY `sales_incentive_lines_sales_period_category` (`sales_id`, `period`, `pricing_item_id`),
    CONSTRAINT `sales_incentive_lines_sales_id_foreign`
        FOREIGN KEY (`sales_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `sales_incentive_lines_barang_id_foreign`
        FOREIGN KEY (`barang_id`) REFERENCES `barangs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `sales_incentive_lines_pricing_item_id_foreign`
        FOREIGN KEY (`pricing_item_id`) REFERENCES `master_pricing_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_incentive_periods` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sales_id` BIGINT UNSIGNED NOT NULL,
    `period` CHAR(7) NOT NULL,
    `cbm_stuffing` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `cbm_paid` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `estimasi_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `cair_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sales_incentive_periods_sales_id_period_unique` (`sales_id`, `period`),
    CONSTRAINT `sales_incentive_periods_sales_id_foreign`
        FOREIGN KEY (`sales_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sales_incentive_tiers` (`name`, `pricing_item_id`, `cbm_per_block`, `amount_per_block`, `sort_order`, `is_active`, `created_at`, `updated_at`)
SELECT 'Per 20 CBM', `crm`.`pricing_item_id`, 20.0000, 500000.00, 1, 1, NOW(), NOW()
FROM `company_revenue_item_modals` AS `crm`
INNER JOIN `master_pricing_items` AS `mpi` ON `mpi`.`id` = `crm`.`pricing_item_id`
WHERE NOT EXISTS (SELECT 1 FROM `sales_incentive_tiers` LIMIT 1)
ORDER BY `crm`.`pricing_item_id`
LIMIT 1;

-- Tabel lama tanpa range: jalankan migrate atau:
-- ALTER TABLE `sales_incentive_tiers`
--   ADD COLUMN `cbm_min` DECIMAL(12,4) NULL DEFAULT NULL AFTER `name`,
--   ADD COLUMN `cbm_max` DECIMAL(12,4) NULL DEFAULT NULL AFTER `cbm_min`;

