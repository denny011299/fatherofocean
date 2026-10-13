-- Kolom komisi/pendapatan (wajib agar approve pembayaran invoice tidak error)
-- Pastikan tabel sales_commissions sudah ada sebelum menjalankan ini.

ALTER TABLE `sales_commissions`
    ADD COLUMN `company_profit_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `billable_base`;

ALTER TABLE `sales_commissions`
    ADD COLUMN `sales_commission_base` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `company_profit_amount`;

ALTER TABLE `sales_commissions`
    ADD COLUMN `cost_subtotal` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `sales_commission_base`;

ALTER TABLE `sales_commissions`
    ADD COLUMN `markup_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `cost_subtotal`;

ALTER TABLE `sales_commissions`
    ADD COLUMN `supervisor_id` BIGINT UNSIGNED NULL AFTER `sales_id`;

ALTER TABLE `sales_commissions`
    ADD COLUMN `supervisor_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `markup_amount`;

ALTER TABLE `sales_commissions`
    ADD COLUMN `settled_at` TIMESTAMP NULL DEFAULT NULL AFTER `paid_at`;

ALTER TABLE `sales_commissions`
    ADD CONSTRAINT `sales_commissions_supervisor_id_foreign`
        FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
