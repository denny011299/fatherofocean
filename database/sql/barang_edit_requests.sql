-- Manual SQL for barang_edit_requests (if migration cannot run)
CREATE TABLE IF NOT EXISTS `barang_edit_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `barang_id` BIGINT UNSIGNED NOT NULL,
    `submitted_by` BIGINT UNSIGNED NOT NULL,
    `reviewed_by` BIGINT UNSIGNED NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `current_snapshot` JSON NOT NULL,
    `proposed_payload` JSON NOT NULL,
    `staged_files` JSON NULL,
    `rejection_note` TEXT NULL,
    `submitted_at` TIMESTAMP NOT NULL,
    `reviewed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `barang_edit_requests_barang_id_status_index` (`barang_id`, `status`),
    INDEX `barang_edit_requests_status_submitted_at_index` (`status`, `submitted_at`),
    CONSTRAINT `barang_edit_requests_barang_id_foreign`
        FOREIGN KEY (`barang_id`) REFERENCES `barangs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `barang_edit_requests_submitted_by_foreign`
        FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `barang_edit_requests_reviewed_by_foreign`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
