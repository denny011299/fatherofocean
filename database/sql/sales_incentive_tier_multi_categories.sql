-- Multi kategori harga modal per aturan insentif sales
-- Prefer: buka /master-data/sales-incentive-tiers/setup-multi-categories (idempotent)

CREATE TABLE IF NOT EXISTS `sales_incentive_tier_pricing_item` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sales_incentive_tier_id` bigint unsigned NOT NULL,
  `pricing_item_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sit_pricing_item_unique` (`sales_incentive_tier_id`, `pricing_item_id`),
  KEY `sit_pricing_item_pricing_idx` (`pricing_item_id`),
  CONSTRAINT `sit_pricing_item_tier_fk`
    FOREIGN KEY (`sales_incentive_tier_id`) REFERENCES `sales_incentive_tiers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sit_pricing_item_item_fk`
    FOREIGN KEY (`pricing_item_id`) REFERENCES `master_pricing_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill dari kolom legacy pricing_item_id
INSERT INTO `sales_incentive_tier_pricing_item`
  (`sales_incentive_tier_id`, `pricing_item_id`, `created_at`, `updated_at`)
SELECT
  `t`.`id`,
  `t`.`pricing_item_id`,
  NOW(),
  NOW()
FROM `sales_incentive_tiers` AS `t`
WHERE `t`.`pricing_item_id` IS NOT NULL
  AND `t`.`pricing_item_id` > 0
  AND NOT EXISTS (
    SELECT 1
    FROM `sales_incentive_tier_pricing_item` AS `p`
    WHERE `p`.`sales_incentive_tier_id` = `t`.`id`
      AND `p`.`pricing_item_id` = `t`.`pricing_item_id`
  );





