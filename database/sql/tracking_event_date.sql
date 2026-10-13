-- Tanggal event tracking (bukan hanya created_at sistem)
-- Prefer: /tracking-staff/setup-event-date (idempotent)

-- Container
ALTER TABLE `container_tracking_statuses`
  ADD COLUMN `event_date` DATE NULL AFTER `description`;
ALTER TABLE `container_tracking_statuses`
  ADD INDEX `container_tracking_statuses_event_date_index` (`event_date`);

UPDATE `container_tracking_statuses`
SET `event_date` = DATE(`created_at`)
WHERE `event_date` IS NULL AND `created_at` IS NOT NULL;

-- Barang
ALTER TABLE `tracking_statuses`
  ADD COLUMN `event_date` DATE NULL AFTER `description`;
ALTER TABLE `tracking_statuses`
  ADD INDEX `tracking_statuses_event_date_index` (`event_date`);

UPDATE `tracking_statuses`
SET `event_date` = DATE(`created_at`)
WHERE `event_date` IS NULL AND `created_at` IS NOT NULL;
