-- Bukti transfer gaji sales/supervisor per bulan (komisi status confirmed/cair)
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
