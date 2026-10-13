-- Add nullable self-FK for marking relation cluster (hub / child markings).
-- Safe to run multiple times: checks information_schema before altering.

SET @db := DATABASE();

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'markings'
      AND COLUMN_NAME = 'related_marking_id'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `markings`
        ADD COLUMN `related_marking_id` BIGINT UNSIGNED NULL AFTER `user_id`,
        ADD CONSTRAINT `markings_related_marking_id_foreign`
            FOREIGN KEY (`related_marking_id`) REFERENCES `markings` (`id`) ON DELETE SET NULL',
    'SELECT ''markings.related_marking_id already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
