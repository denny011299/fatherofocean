-- Insentif sales per kategori harga modal (pricing_item_id)
-- Jalankan setelah migration 2026_06_13_100000_add_pricing_item_to_sales_incentive.php

ALTER TABLE `sales_incentive_tiers`
    ADD COLUMN `pricing_item_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `name`,
    ADD INDEX `sales_incentive_tiers_pricing_item_id_sort_order_index` (`pricing_item_id`, `sort_order`),
    ADD CONSTRAINT `sales_incentive_tiers_pricing_item_id_foreign`
        FOREIGN KEY (`pricing_item_id`) REFERENCES `master_pricing_items` (`id`) ON DELETE SET NULL;

ALTER TABLE `sales_incentive_lines`
    ADD COLUMN `pricing_item_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `barang_id`,
    ADD INDEX `sales_incentive_lines_sales_period_category` (`sales_id`, `period`, `pricing_item_id`),
    ADD CONSTRAINT `sales_incentive_lines_pricing_item_id_foreign`
        FOREIGN KEY (`pricing_item_id`) REFERENCES `master_pricing_items` (`id`) ON DELETE SET NULL;

-- Backfill kategori baris insentif dari cbm_type barang
UPDATE `sales_incentive_lines` AS `sil`
INNER JOIN `barangs` AS `b` ON `b`.`id` = `sil`.`barang_id`
SET `sil`.`pricing_item_id` = (
    SELECT `mpi`.`id`
    FROM `master_pricing_items` AS `mpi`
    WHERE LOWER(TRIM(`mpi`.`name`)) = LOWER(TRIM(`b`.`cbm_type`))
       OR CAST(`mpi`.`id` AS CHAR) = TRIM(`b`.`cbm_type`)
    LIMIT 1
)
WHERE `sil`.`pricing_item_id` IS NULL
  AND TRIM(COALESCE(`b`.`cbm_type`, '')) <> '';
