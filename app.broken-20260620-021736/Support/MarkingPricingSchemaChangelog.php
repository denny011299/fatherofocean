<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class MarkingPricingSchemaChangelog
{
    public const MIGRATION_FILE = '2026_05_18_000001_move_minimum_charge_to_markings_table.php';

    /** @return list<array{file: string, label: string, statements: list<string>}> */
    public static function sessionMigrationDefinitions(): array
    {
        return [
            [
                'file' => '2026_05_17_000001_add_marking_code_to_markings_if_missing.php',
                'label' => 'Marking code column',
                'statements' => [
                    "ALTER TABLE `markings` ADD `code` VARCHAR(50) NULL AFTER `user_id`;",
                    'ALTER TABLE `markings` ADD UNIQUE `markings_code_unique` (`code`);',
                ],
            ],
            [
                'file' => '2026_05_17_000002_add_pricing_settings_to_markings_table.php',
                'label' => 'Pricing settings on markings',
                'statements' => [
                    "ALTER TABLE `markings` ADD `effective_start` DATE NULL AFTER `passcode`;",
                    "ALTER TABLE `markings` ADD `invoice_due_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `effective_start`;",
                    "ALTER TABLE `markings` ADD `max_weight_kg` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `invoice_due_days`;",
                    '-- Copy latest marking_pricings row per marking into markings (effective_start, invoice_due_days, max_weight_kg)',
                ],
            ],
            [
                'file' => self::MIGRATION_FILE,
                'label' => 'Global Min. Charge on markings',
                'statements' => self::minimumChargeSqlStatements(),
            ],
            [
                'file' => '2026_05_18_000001_create_repricing_requests_table.php',
                'label' => 'Repricing approval requests',
                'statements' => [
                    'CREATE TABLE `repricing_requests` (',
                    '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,',
                    '  `barang_id` BIGINT UNSIGNED NOT NULL,',
                    '  `submitted_by` BIGINT UNSIGNED NOT NULL,',
                    '  `reviewed_by` BIGINT UNSIGNED NULL,',
                    "  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',",
                    '  `old_price_per_cbm` DECIMAL(12,2) NOT NULL DEFAULT 0,',
                    '  `old_extra_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,',
                    '  `new_price_per_cbm` DECIMAL(12,2) NOT NULL,',
                    '  `new_extra_charge` DECIMAL(12,2) NOT NULL DEFAULT 0,',
                    '  `rejection_note` TEXT NULL,',
                    '  `submitted_at` TIMESTAMP NOT NULL,',
                    '  `reviewed_at` TIMESTAMP NULL,',
                    '  `created_at` TIMESTAMP NULL,',
                    '  `updated_at` TIMESTAMP NULL,',
                    '  INDEX `repricing_requests_barang_id_status_index` (`barang_id`, `status`),',
                    '  CONSTRAINT `repricing_requests_barang_id_foreign` FOREIGN KEY (`barang_id`) REFERENCES `barangs` (`id`) ON DELETE CASCADE,',
                    '  CONSTRAINT `repricing_requests_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,',
                    '  CONSTRAINT `repricing_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
                    ');',
                ],
            ],
            [
                'file' => '2026_05_18_000001_drop_removed_columns_from_barang_dimensions_table.php',
                'label' => 'Drop unused barang_dimensions columns',
                'statements' => [
                    'ALTER TABLE `barang_dimensions`',
                    '  DROP COLUMN `ctn_no`,',
                    '  DROP COLUMN `qty_ctn`,',
                    '  DROP COLUMN `qty`,',
                    '  DROP COLUMN `circ`,',
                    '  DROP COLUMN `round_val`,',
                    '  DROP COLUMN `va`;',
                ],
            ],
            [
                'file' => '2026_05_18_000002_drop_province_city_from_markings_table.php',
                'label' => 'Drop province/city from markings',
                'statements' => [
                    'ALTER TABLE `markings` DROP FOREIGN KEY `markings_city_id_foreign`;',
                    'ALTER TABLE `markings` DROP COLUMN `city_id`;',
                    'ALTER TABLE `markings` DROP FOREIGN KEY `markings_province_id_foreign`;',
                    'ALTER TABLE `markings` DROP COLUMN `province_id`;',
                ],
            ],
            [
                'file' => '2026_05_18_000003_add_ktp_photo_path_to_markings_table.php',
                'label' => 'KTP photo path on markings',
                'statements' => [
                    "ALTER TABLE `markings` ADD `ktp_photo_path` VARCHAR(255) NULL AFTER `nik_name`;",
                ],
            ],
            [
                'file' => '2026_05_18_100001_create_master_promos_table.php',
                'label' => 'Master promos table',
                'statements' => [
                    'CREATE TABLE `master_promos` (',
                    '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,',
                    '  `name` VARCHAR(100) NOT NULL,',
                    '  `persen` DECIMAL(5,2) NOT NULL DEFAULT 0,',
                    '  `is_active` TINYINT(1) NOT NULL DEFAULT 1,',
                    '  `created_at` TIMESTAMP NULL,',
                    '  `updated_at` TIMESTAMP NULL',
                    ');',
                ],
            ],
            [
                'file' => '2026_05_18_100002_add_promo_to_invoices_table.php',
                'label' => 'Promo fields on invoices',
                'statements' => [
                    'ALTER TABLE `invoices` ADD `promo_id` BIGINT UNSIGNED NULL AFTER `extra_charges`;',
                    'ALTER TABLE `invoices` ADD CONSTRAINT `invoices_promo_id_foreign` FOREIGN KEY (`promo_id`) REFERENCES `master_promos` (`id`) ON DELETE SET NULL;',
                    "ALTER TABLE `invoices` ADD `promo_name` VARCHAR(100) NULL AFTER `promo_id`;",
                    'ALTER TABLE `invoices` ADD `promo_persen` DECIMAL(5,2) NULL AFTER `promo_name`;',
                    'ALTER TABLE `invoices` ADD `promo_discount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `promo_persen`;',
                ],
            ],
            [
                'file' => '2026_05_18_100003_drop_promo_from_markings_table.php',
                'label' => 'Drop promo column from markings',
                'statements' => [
                    'ALTER TABLE `markings` DROP COLUMN `promo`;',
                ],
            ],
            [
                'file' => '2026_05_18_120000_drop_net_weight_kg_from_barang_products_table.php',
                'label' => 'Drop net_weight_kg from barang_products',
                'statements' => [
                    'ALTER TABLE `barang_products` DROP COLUMN `net_weight_kg`;',
                ],
            ],
        ];
    }

    /** @return list<array{file: string, label: string, statements: list<string>, status: string}> */
    public static function sessionMigrations(): array
    {
        $statusByFile = [
            '2026_05_17_000001_add_marking_code_to_markings_if_missing.php' => self::markingCodeStatus(),
            '2026_05_17_000002_add_pricing_settings_to_markings_table.php' => self::pricingSettingsStatus(),
            self::MIGRATION_FILE => self::minimumChargeStatus(),
            '2026_05_18_000001_create_repricing_requests_table.php' => Schema::hasTable('repricing_requests') ? 'applied' : 'pending',
            '2026_05_18_000001_drop_removed_columns_from_barang_dimensions_table.php' => self::barangDimensionsDropStatus(),
            '2026_05_18_000002_drop_province_city_from_markings_table.php' => self::provinceCityDropStatus(),
            '2026_05_18_000003_add_ktp_photo_path_to_markings_table.php' => Schema::hasColumn('markings', 'ktp_photo_path') ? 'applied' : 'pending',
            '2026_05_18_100001_create_master_promos_table.php' => Schema::hasTable('master_promos') ? 'applied' : 'pending',
            '2026_05_18_100002_add_promo_to_invoices_table.php' => Schema::hasColumn('invoices', 'promo_id') ? 'applied' : 'pending',
            '2026_05_18_100003_drop_promo_from_markings_table.php' => self::markingPromoDropStatus(),
            '2026_05_18_120000_drop_net_weight_kg_from_barang_products_table.php' => self::barangProductsNetWeightStatus(),
        ];

        return array_map(static function (array $definition) use ($statusByFile): array {
            return [
                ...$definition,
                'status' => $statusByFile[$definition['file']] ?? 'pending',
            ];
        }, self::sessionMigrationDefinitions());
    }

    /** @return list<string> */
    public static function minimumChargeSqlStatements(): array
    {
        return [
            "ALTER TABLE `markings` ADD `minimum_charge` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `max_weight_kg`;",
            'UPDATE `markings` m SET `minimum_charge` = COALESCE(('
                . 'SELECT MAX(mp.`minimum_charge`) FROM `marking_pricings` mp WHERE mp.`marking_id` = m.`id`'
                . '), 0);',
            'ALTER TABLE `marking_pricings` DROP COLUMN `minimum_charge`;',
        ];
    }

    public static function isApplied(): bool
    {
        return self::minimumChargeStatus() === 'applied';
    }

    public static function isPending(): bool
    {
        return self::minimumChargeStatus() === 'pending';
    }

    public static function needsAttention(): bool
    {
        foreach (self::sessionMigrations() as $migration) {
            if (($migration['status'] ?? 'applied') !== 'applied') {
                return true;
            }
        }

        return false;
    }

    /** @return array{status: string, migration: string, statements: list<string>, migrations: list<array{file: string, label: string, statements: list<string>, status: string}>, has_pending: bool} */
    public static function panel(): array
    {
        $migrations = self::sessionMigrations();
        $status = self::minimumChargeStatus();

        return [
            'status' => $status,
            'migration' => self::MIGRATION_FILE,
            'statements' => self::minimumChargeSqlStatements(),
            'migrations' => $migrations,
            'has_pending' => self::needsAttention(),
        ];
    }

    private static function minimumChargeStatus(): string
    {
        if (Schema::hasColumn('markings', 'minimum_charge')
            && ! Schema::hasColumn('marking_pricings', 'minimum_charge')) {
            return 'applied';
        }

        if (! Schema::hasColumn('markings', 'minimum_charge')
            && Schema::hasColumn('marking_pricings', 'minimum_charge')) {
            return 'pending';
        }

        return 'partial';
    }

    private static function markingCodeStatus(): string
    {
        if (! Schema::hasTable('markings')) {
            return 'pending';
        }

        return Schema::hasColumn('markings', 'code') ? 'applied' : 'pending';
    }

    private static function pricingSettingsStatus(): string
    {
        if (! Schema::hasTable('markings')) {
            return 'pending';
        }

        $hasAll = Schema::hasColumn('markings', 'effective_start')
            && Schema::hasColumn('markings', 'invoice_due_days')
            && Schema::hasColumn('markings', 'max_weight_kg');

        return $hasAll ? 'applied' : 'pending';
    }

    private static function barangDimensionsDropStatus(): string
    {
        if (! Schema::hasTable('barang_dimensions')) {
            return 'pending';
        }

        return Schema::hasColumn('barang_dimensions', 'ctn_no') ? 'pending' : 'applied';
    }

    private static function provinceCityDropStatus(): string
    {
        if (! Schema::hasTable('markings')) {
            return 'pending';
        }

        if (Schema::hasColumn('markings', 'province_id') || Schema::hasColumn('markings', 'city_id')) {
            return 'pending';
        }

        return 'applied';
    }

    private static function markingPromoDropStatus(): string
    {
        if (! Schema::hasTable('markings')) {
            return 'pending';
        }

        if (! Schema::hasColumn('markings', 'promo')) {
            return 'applied';
        }

        return 'pending';
    }

    private static function barangProductsNetWeightStatus(): string
    {
        if (! Schema::hasTable('barang_products')) {
            return 'pending';
        }

        return Schema::hasColumn('barang_products', 'net_weight_kg') ? 'pending' : 'applied';
    }
}
