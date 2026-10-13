-- =============================================================================
-- PATCH PRODUCTION — database ocew9596_ocean (dump 2026-05-22)
-- Jalankan di phpMyAdmin / MySQL. Aman dijalankan ulang (idempotent).
-- Setelah ini di server Laravel:
--   php artisan db:seed --class=RolePermissionSeeder
--   php artisan permission:cache-reset
--   php artisan storage:link
--   php artisan config:clear && php artisan cache:clear
-- =============================================================================

-- -----------------------------------------------------------------------------
-- A. CEK CEPAT (opsional — lihat hasilnya saja)
-- -----------------------------------------------------------------------------
-- SELECT TABLE_NAME FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND TABLE_NAME IN (
--       'sales_commissions','sales_salary_payouts','user_subordinates',
--       'user_notifications','payments','system_settings'
--     );

-- -----------------------------------------------------------------------------
-- B. TABEL — hanya jika BELUM ada (skip jika sudah dari dump/migrate)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_subordinates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_user_id` BIGINT UNSIGNED NOT NULL,
    `subordinate_user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_subordinates_parent_subordinate_unique` (`parent_user_id`, `subordinate_user_id`),
    KEY `user_subordinates_subordinate_user_id_foreign` (`subordinate_user_id`),
    CONSTRAINT `user_subordinates_parent_user_id_foreign`
        FOREIGN KEY (`parent_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `user_subordinates_subordinate_user_id_foreign`
        FOREIGN KEY (`subordinate_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_salary_payouts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sales_id` BIGINT UNSIGNED NOT NULL,
    `period` CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    `proof_file` VARCHAR(255) NULL DEFAULT NULL,
    `proof_uploaded_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `proof_uploaded_at` TIMESTAMP NULL DEFAULT NULL,
    `admin_note` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sales_salary_payouts_sales_period_unique` (`sales_id`, `period`),
    KEY `sales_salary_payouts_period_index` (`period`),
    CONSTRAINT `sales_salary_payouts_sales_id_foreign`
        FOREIGN KEY (`sales_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `sales_salary_payouts_proof_uploaded_by_foreign`
        FOREIGN KEY (`proof_uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,image.png
    `user_id` BIGINT UNSIGNED NOT NULL,
    `type` VARCHAR(40) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT NULL DEFAULT NULL,
    `data` JSON NULL DEFAULT NULL,
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `user_notifications_user_id_read_at_index` (`user_id`, `read_at`),
    CONSTRAINT `user_notifications_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_commissions + kolom extend: di dump SUDAH LENGKAP — jangan ALTER ulang

-- Insentif sales — jalankan file terpisah jika patch ini di phpMyAdmin:
--   database/sql/sales_incentive.sql

-- -----------------------------------------------------------------------------
-- C. SYSTEM SETTINGS (dump punya company_revenue_fixed_amount, belum ada rate 2,5%)
-- -----------------------------------------------------------------------------

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('sales_commission_rate_default', '2.5', NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = '2.5', `updated_at` = NOW();

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('company_revenue_fixed_amount', '150000', NOW(), NOW())
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW();

-- -----------------------------------------------------------------------------
-- D. PERMISSION yang belum ada di dump production (id > 55)
-- -----------------------------------------------------------------------------

INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT t.`name`, 'web', NOW(), NOW()
FROM (
    SELECT 'view all marking' AS `name`
    UNION SELECT 'export marking'
    UNION SELECT 'approve barang'
    UNION SELECT 'reject barang'
    UNION SELECT 'download barang invoice'
    UNION SELECT 'edit barang product logistics'
    UNION SELECT 'edit rmb rate'
    UNION SELECT 'submit repricing'
    UNION SELECT 'approve repricing'
    UNION SELECT 'view pengajuan'
    UNION SELECT 'upload sales salary proof'
    UNION SELECT 'view sales incentive'
    UNION SELECT 'manage sales incentive master'
    UNION SELECT 'view promo master'
    UNION SELECT 'create promo master'
    UNION SELECT 'edit promo master'
    UNION SELECT 'delete promo master'
    UNION SELECT 'apply invoice promo'
    UNION SELECT 'export omset'
    UNION SELECT 'manage roles'
) AS t
WHERE NOT EXISTS (
    SELECT 1 FROM `permissions` p
    WHERE p.`name` = t.`name` AND p.`guard_name` = 'web'
);

-- -----------------------------------------------------------------------------
-- E. HAK AKSES role (pakai NAMA role, bukan ID — aman di production)
-- super_admin=7, supervisor=6, finance=3, admin_cs=2, sales=8
-- -----------------------------------------------------------------------------

-- Finance: menu keuangan lengkap + gaji
INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.`id`, r.`id`
FROM `permissions` p
INNER JOIN `roles` r ON r.`name` = 'finance' AND r.`guard_name` = 'web'
WHERE p.`guard_name` = 'web'
  AND p.`name` IN (
    'edit rmb rate',
    'submit repricing', 'approve repricing', 'view pengajuan',
    'apply invoice promo', 'export omset',
    'upload sales salary proof', 'view sales incentive', 'manage sales incentive master'
  )
  AND NOT EXISTS (
    SELECT 1 FROM `role_has_permissions` x
    WHERE x.`permission_id` = p.`id` AND x.`role_id` = r.`id`
  );

-- Super admin & supervisor: semua permission baru
INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.`id`, r.`id`
FROM `permissions` p
INNER JOIN `roles` r ON r.`name` IN ('super_admin', 'supervisor') AND r.`guard_name` = 'web'
WHERE p.`guard_name` = 'web'
  AND p.`name` IN (
    'view all marking', 'export marking',
    'approve barang', 'reject barang', 'download barang invoice', 'edit barang product logistics',
    'edit rmb rate',
    'submit repricing', 'approve repricing', 'view pengajuan',
    'upload sales salary proof', 'view sales incentive',
    'view promo master', 'create promo master', 'edit promo master', 'delete promo master',
    'apply invoice promo', 'export omset', 'manage roles'
  )
  AND NOT EXISTS (
    SELECT 1 FROM `role_has_permissions` x
    WHERE x.`permission_id` = p.`id` AND x.`role_id` = r.`id`
  );

-- Admin CS: gaji + export marking (sesuai seeder)
INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.`id`, r.`id`
FROM `permissions` p
INNER JOIN `roles` r ON r.`name` = 'admin_cs' AND r.`guard_name` = 'web'
WHERE p.`guard_name` = 'web'
  AND p.`name` IN (
    'upload sales salary proof', 'export marking', 'view all marking',
    'view sales revenue', 'view sales incentive', 'manage sales incentive master',
    'confirm sales commission', 'mark sales commission cair'
  )
  AND NOT EXISTS (
    SELECT 1 FROM `role_has_permissions` x
    WHERE x.`permission_id` = p.`id` AND x.`role_id` = r.`id`
  );

-- Sales: repricing + pengajuan (sesuai seeder)
INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.`id`, r.`id`
FROM `permissions` p
INNER JOIN `roles` r ON r.`name` = 'sales' AND r.`guard_name` = 'web'
WHERE p.`guard_name` = 'web'
  AND p.`name` IN ('submit repricing', 'view pengajuan', 'view sales incentive')
  AND NOT EXISTS (
    SELECT 1 FROM `role_has_permissions` x
    WHERE x.`permission_id` = p.`id` AND x.`role_id` = r.`id`
  );

-- Permission: lihat semua notifikasi (filter marking untuk sales/supervisor tetap di aplikasi)
INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'view all notifications', 'web', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `permissions` WHERE `name` = 'view all notifications' AND `guard_name` = 'web'
);

INSERT INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.`id`, r.`id`
FROM `permissions` p
INNER JOIN `roles` r ON r.`name` IN ('admin_cs', 'finance', 'admin_gudang_china', 'admin_gudang_indo') AND r.`guard_name` = 'web'
WHERE p.`guard_name` = 'web'
  AND p.`name` = 'view all notifications'
  AND NOT EXISTS (
    SELECT 1 FROM `role_has_permissions` x
    WHERE x.`permission_id` = p.`id` AND x.`role_id` = r.`id`
  );

-- -----------------------------------------------------------------------------
-- G. barang_products.parent_id — sub-item per box (mixed contents)
-- -----------------------------------------------------------------------------
SET @db := DATABASE();

SET @has_parent_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'barang_products' AND COLUMN_NAME = 'parent_id'
);

SET @ddl_parent := IF(
    @has_parent_col = 0,
    'ALTER TABLE `barang_products`
        ADD COLUMN `parent_id` BIGINT UNSIGNED NULL AFTER `barang_id`,
        ADD CONSTRAINT `barang_products_parent_id_foreign`
            FOREIGN KEY (`parent_id`) REFERENCES `barang_products` (`id`) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_parent FROM @ddl_parent;
EXECUTE stmt_parent;
DEALLOCATE PREPARE stmt_parent;

-- -----------------------------------------------------------------------------
-- H. Bongkar muat (unload check before Surat Jalan)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bongkar_muat_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `container_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'in_progress',
    `notes` TEXT NULL,
    `started_by` BIGINT UNSIGNED NOT NULL,
    `completed_by` BIGINT UNSIGNED NULL,
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `bongkar_muat_sessions_container_id_status_index` (`container_id`, `status`),
    CONSTRAINT `bongkar_muat_sessions_container_id_foreign` FOREIGN KEY (`container_id`) REFERENCES `containers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bongkar_muat_sessions_started_by_foreign` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bongkar_muat_sessions_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bongkar_muat_scans` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `bongkar_muat_session_id` BIGINT UNSIGNED NOT NULL,
    `barang_id` BIGINT UNSIGNED NOT NULL,
    `no_index` VARCHAR(50) NOT NULL,
    `expected_ctns` INT UNSIGNED NOT NULL,
    `confirmed_ctns` INT UNSIGNED NOT NULL,
    `variance_note` VARCHAR(500) NULL,
    `scanned_by` BIGINT UNSIGNED NOT NULL,
    `scanned_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `bongkar_muat_scans_session_barang_unique` (`bongkar_muat_session_id`, `barang_id`),
    INDEX `bongkar_muat_scans_no_index_index` (`no_index`),
    CONSTRAINT `bongkar_muat_scans_session_id_foreign` FOREIGN KEY (`bongkar_muat_session_id`) REFERENCES `bongkar_muat_sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bongkar_muat_scans_barang_id_foreign` FOREIGN KEY (`barang_id`) REFERENCES `barangs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bongkar_muat_scans_scanned_by_foreign` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'view bongkar muat', 'web', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'view bongkar muat' AND `guard_name` = 'web');

INSERT INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'manage bongkar muat', 'web', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'manage bongkar muat' AND `guard_name` = 'web');
