-- Relasi user induk ↔ anak buah (portal gabungan barang saat login)
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
