-- Dashboard widget permissions (jalankan sekali di phpMyAdmin jika belum ada di tabel permissions)
-- Setelah upload PermissionCatalog.php, buka halaman Role & Hak Akses juga otomatis mendaftarkan permission ini.

INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES
('view dashboard', 'web', NOW(), NOW()),
('manage task', 'web', NOW(), NOW()),
('assign feedback', 'web', NOW(), NOW()),
('view dashboard widget omset', 'web', NOW(), NOW()),
('view dashboard widget total cbm', 'web', NOW(), NOW()),
('view dashboard widget shipment chart', 'web', NOW(), NOW()),
('view dashboard widget active shipments', 'web', NOW(), NOW()),
('view dashboard widget master marking', 'web', NOW(), NOW()),
('view dashboard widget invoices', 'web', NOW(), NOW()),
('view dashboard widget sales revenue', 'web', NOW(), NOW()),
('view dashboard widget company value', 'web', NOW(), NOW()),
('view dashboard widget feedback', 'web', NOW(), NOW()),
('view dashboard widget daily task', 'web', NOW(), NOW());
    