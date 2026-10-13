-- =============================================================================
-- Pelayaran — manual MySQL schema (session 2026-05-17 / 2026-05-18)
-- =============================================================================
-- Run on production MySQL 8+ (uses INFORMATION_SCHEMA + prepared statements).
--
-- OPTION A: php artisan migrate  (records migrations table automatically)
-- OPTION B: run THIS file only     (does NOT insert into `migrations` table)
--
-- If you use OPTION B only, either run migrate afterward (skips applied steps)
-- or insert migration rows manually so Laravel stays in sync.
--
-- Backup database before running. Test on staging first.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 2026_05_17_000001_add_marking_code_to_markings_if_missing.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'code') = 0,
    'ALTER TABLE `markings` ADD COLUMN `code` VARCHAR(50) NULL AFTER `user_id`',
    'SELECT ''skip: markings.code already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'code') > 0
    AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND INDEX_NAME = 'markings_code_unique') = 0,
    'ALTER TABLE `markings` ADD UNIQUE KEY `markings_code_unique` (`code`)',
    'SELECT ''skip: markings_code_unique already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_17_000002_add_pricing_settings_to_markings_table.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'effective_start') = 0,
    'ALTER TABLE `markings` ADD COLUMN `effective_start` DATE NULL AFTER `passcode`',
    'SELECT ''skip: markings.effective_start'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'invoice_due_days') = 0,
    'ALTER TABLE `markings` ADD COLUMN `invoice_due_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `effective_start`',
    'SELECT ''skip: markings.invoice_due_days'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'max_weight_kg') = 0,
    'ALTER TABLE `markings` ADD COLUMN `max_weight_kg` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `invoice_due_days`',
    'SELECT ''skip: markings.max_weight_kg'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill from latest marking_pricings row per marking (if table exists)
SET @has_mp = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marking_pricings');

SET @sql = IF(@has_mp > 0,
    'UPDATE `markings` AS m
     INNER JOIN `marking_pricings` AS mp ON mp.`marking_id` = m.`id`
     LEFT JOIN `marking_pricings` AS mp2 ON mp2.`marking_id` = m.`id`
         AND (
             mp2.`effective_start` > mp.`effective_start`
             OR (mp2.`effective_start` <=> mp.`effective_start` AND mp2.`id` > mp.`id`)
         )
     SET
         m.`effective_start` = mp.`effective_start`,
         m.`invoice_due_days` = COALESCE(mp.`invoice_due_days`, 0),
         m.`max_weight_kg` = COALESCE(mp.`weight_threshold_kg`, 0)
     WHERE mp2.`id` IS NULL',
    'SELECT ''skip: marking_pricings missing — no backfill'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_000001_create_repricing_requests_table.php
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `repricing_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `barang_id` BIGINT UNSIGNED NOT NULL,
    `submitted_by` BIGINT UNSIGNED NOT NULL,
    `reviewed_by` BIGINT UNSIGNED NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `old_price_per_cbm` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `old_extra_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `new_price_per_cbm` DECIMAL(12,2) NOT NULL,
    `new_extra_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `rejection_note` TEXT NULL,
    `submitted_at` TIMESTAMP NOT NULL,
    `reviewed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `repricing_requests_barang_id_status_index` (`barang_id`, `status`),
    CONSTRAINT `repricing_requests_barang_id_foreign`
        FOREIGN KEY (`barang_id`) REFERENCES `barangs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `repricing_requests_submitted_by_foreign`
        FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `repricing_requests_reviewed_by_foreign`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2026_05_18_000001_drop_removed_columns_from_barang_dimensions_table.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang_dimensions' AND COLUMN_NAME = 'ctn_no') > 0,
    'ALTER TABLE `barang_dimensions`
        DROP COLUMN `ctn_no`,
        DROP COLUMN `qty_ctn`,
        DROP COLUMN `qty`,
        DROP COLUMN `circ`,
        DROP COLUMN `round_val`,
        DROP COLUMN `va`',
    'SELECT ''skip: barang_dimensions columns already dropped'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_000001_move_minimum_charge_to_markings_table.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'minimum_charge') = 0,
    'ALTER TABLE `markings` ADD COLUMN `minimum_charge` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `max_weight_kg`',
    'SELECT ''skip: markings.minimum_charge'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mp_mc = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marking_pricings' AND COLUMN_NAME = 'minimum_charge'
);

SET @sql = IF(@has_mp_mc > 0,
    'UPDATE `markings` AS m
     INNER JOIN (
         SELECT `marking_id`, MAX(`minimum_charge`) AS `max_min`
         FROM `marking_pricings`
         GROUP BY `marking_id`
     ) AS x ON x.`marking_id` = m.`id`
     SET m.`minimum_charge` = x.`max_min`',
    'SELECT ''skip: marking_pricings.minimum_charge missing'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@has_mp_mc > 0,
    'ALTER TABLE `marking_pricings` DROP COLUMN `minimum_charge`',
    'SELECT ''skip: drop marking_pricings.minimum_charge'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_000002_drop_province_city_from_markings_table.php
-- -----------------------------------------------------------------------------
SET @fk_city = (
    SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'city_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1
);
SET @sql = IF(@fk_city IS NOT NULL,
    CONCAT('ALTER TABLE `markings` DROP FOREIGN KEY `', @fk_city, '`'),
    'SELECT ''skip: markings.city_id FK'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'city_id') > 0,
    'ALTER TABLE `markings` DROP COLUMN `city_id`',
    'SELECT ''skip: markings.city_id'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_prov = (
    SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'province_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1
);
SET @sql = IF(@fk_prov IS NOT NULL,
    CONCAT('ALTER TABLE `markings` DROP FOREIGN KEY `', @fk_prov, '`'),
    'SELECT ''skip: markings.province_id FK'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'province_id') > 0,
    'ALTER TABLE `markings` DROP COLUMN `province_id`',
    'SELECT ''skip: markings.province_id'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_000003_add_ktp_photo_path_to_markings_table.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'ktp_photo_path') = 0,
    'ALTER TABLE `markings` ADD COLUMN `ktp_photo_path` VARCHAR(255) NULL AFTER `nik_name`',
    'SELECT ''skip: markings.ktp_photo_path'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_000004_move_marking_prices_to_master_pricing_items.php
-- Fixes SQLSTATE 1054: Unknown column volume_price / gw_price
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'master_pricing_items' AND COLUMN_NAME = 'volume_price') = 0,
    'ALTER TABLE `master_pricing_items` ADD COLUMN `volume_price` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `name`',
    'SELECT ''skip: master_pricing_items.volume_price'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'master_pricing_items' AND COLUMN_NAME = 'gw_price') = 0,
    'ALTER TABLE `master_pricing_items` ADD COLUMN `gw_price` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `volume_price`',
    'SELECT ''skip: master_pricing_items.gw_price'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_mp_prices = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marking_pricings' AND COLUMN_NAME IN ('volume_price', 'gw_price')
);
SET @has_mp_prices = IF(@has_mp_prices = 2, 1, 0);

SET @sql = IF(@has_mp_prices > 0,
    'UPDATE `master_pricing_items` AS mpi
     INNER JOIN (
         SELECT mp.`pricing_item_id`, mp.`volume_price`, mp.`gw_price`
         FROM `marking_pricings` AS mp
         INNER JOIN (
             SELECT `pricing_item_id`, MAX(`id`) AS `max_id`
             FROM `marking_pricings`
             GROUP BY `pricing_item_id`
         ) AS latest ON latest.`pricing_item_id` = mp.`pricing_item_id` AND latest.`max_id` = mp.`id`
     ) AS src ON src.`pricing_item_id` = mpi.`id`
     SET
         mpi.`volume_price` = COALESCE(src.`volume_price`, 0),
         mpi.`gw_price` = COALESCE(src.`gw_price`, 0)
     WHERE mpi.`volume_price` = 0 AND mpi.`gw_price` = 0',
    'SELECT ''skip: marking_pricings price columns missing'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@has_mp_prices > 0,
    'ALTER TABLE `marking_pricings` DROP COLUMN `volume_price`, DROP COLUMN `gw_price`',
    'SELECT ''skip: drop marking_pricings volume_price/gw_price'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_100001_create_master_promos_table.php
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `master_promos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `persen` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2026_05_18_100002_add_promo_to_invoices_table.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'promo_id') = 0,
    'ALTER TABLE `invoices`
        ADD COLUMN `promo_id` BIGINT UNSIGNED NULL AFTER `extra_charges`,
        ADD COLUMN `promo_name` VARCHAR(100) NULL AFTER `promo_id`,
        ADD COLUMN `promo_persen` DECIMAL(5,2) NULL AFTER `promo_name`,
        ADD COLUMN `promo_discount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `promo_persen`',
    'SELECT ''skip: invoices promo columns'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND CONSTRAINT_NAME = 'invoices_promo_id_foreign') = 0
    AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'promo_id') > 0,
    'ALTER TABLE `invoices`
        ADD CONSTRAINT `invoices_promo_id_foreign`
        FOREIGN KEY (`promo_id`) REFERENCES `master_promos` (`id`) ON DELETE SET NULL',
    'SELECT ''skip: invoices_promo_id_foreign'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_100003_drop_promo_from_markings_table.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'markings' AND COLUMN_NAME = 'promo') > 0,
    'ALTER TABLE `markings` DROP COLUMN `promo`',
    'SELECT ''skip: markings.promo'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_120000_drop_net_weight_kg_from_barang_products_table.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang_products' AND COLUMN_NAME = 'net_weight_kg') > 0,
    'ALTER TABLE `barang_products` DROP COLUMN `net_weight_kg`',
    'SELECT ''skip: barang_products.net_weight_kg'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2026_05_18_130000_drop_weight_from_barangs_table.php
-- -----------------------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barangs' AND COLUMN_NAME = 'weight') > 0,
    'ALTER TABLE `barangs` DROP COLUMN `weight`',
    'SELECT ''skip: barangs.weight'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- END — verify with:
--   SHOW COLUMNS FROM master_pricing_items LIKE 'volume_price';
--   SHOW COLUMNS FROM markings LIKE 'minimum_charge';
--   SHOW TABLES LIKE 'repricing_requests';
-- =============================================================================
