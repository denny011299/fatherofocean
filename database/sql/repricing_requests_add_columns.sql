-- Jalankan di MySQL (phpMyAdmin / CLI) jika migrate belum dijalankan.
-- Abaikan error "Duplicate column name" jika kolom sudah ada.

ALTER TABLE `repricing_requests`
    ADD COLUMN `pricing_mode` VARCHAR(10) NOT NULL DEFAULT 'cbm' COMMENT 'cbm|weight|split' AFTER `status`;

ALTER TABLE `repricing_requests`
    ADD COLUMN `old_weight_price` DECIMAL(12, 2) NULL DEFAULT NULL AFTER `old_extra_charge`;

ALTER TABLE `repricing_requests`
    ADD COLUMN `new_weight_price` DECIMAL(12, 2) NULL DEFAULT NULL AFTER `new_extra_charge`;

ALTER TABLE `repricing_requests`
    ADD COLUMN `reason` TEXT NULL DEFAULT NULL AFTER `rejection_note`;

ALTER TABLE `repricing_requests`
    ADD COLUMN `proof_path` VARCHAR(255) NULL DEFAULT NULL AFTER `reason`;

-- Threshold CBM/kg (max kg per 1 CBM) on Instant Pricing repricing flow.
ALTER TABLE `repricing_requests`
    ADD COLUMN `old_weight_threshold_kg` DECIMAL(15, 2) NULL DEFAULT NULL AFTER `old_minimum_charge_cbm`;

ALTER TABLE `repricing_requests`
    ADD COLUMN `new_weight_threshold_kg` DECIMAL(15, 2) NULL DEFAULT NULL AFTER `new_minimum_charge_cbm`;
