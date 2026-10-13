-- Komisi khusus per user (nullable = pakai default sistem per role)
ALTER TABLE `users`
    ADD COLUMN `sales_commission_rate_override` DECIMAL(5, 2) NULL
    COMMENT 'Persentase komisi khusus; NULL = umum'
    AFTER `phone`;
