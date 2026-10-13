<?php

declare(strict_types=1);

namespace App\Support;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Daftar permission resmi aplikasi — dipakai seeder, migrasi, dan halaman Role. */
final class PermissionCatalog
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            // Dashboard
            'view dashboard',
            'manage task',
            'assign feedback',
            ...DashboardWidgetPermissions::all(),

            // Marking
            'view marking',
            'view all marking',
            'create marking',
            'edit marking',
            'delete marking',
            'manage marking pricing',
            'export marking',

            // Notifikasi
            'view all notifications',

            // Barang
            'view barang',
            'create barang',
            'edit barang',
            'delete barang',
            'export barang',
            'approve barang',
            'reject barang',
            'download barang invoice',
            'edit barang product logistics',
            'view harga modal',
            'edit harga modal',

            // Container
            'view container',
            'create container',
            'edit container',
            'delete container',
            'load barang',
            'unload barang',

            // Surat Jalan
            'view delivery trip',
            'create delivery trip',
            'manage delivery trip',
            'confirm delivery trip',
            'export delivery trip pdf',
            'delete delivery trip',

            // Bongkar muat
            'view bongkar muat',
            'manage bongkar muat',

            // Keuangan
            'edit rmb rate',
            'view invoice',
            'create invoice',
            'change price',
            'submit repricing',
            'approve repricing',
            'view pengajuan',
            'approve payment',
            'reject payment',
            'view claim',
            'create claim',
            'approve claim',
            'reject claim',
            'view pricing',
            'view sales revenue',
            'confirm sales commission',
            'mark sales commission cair',
            'upload sales salary proof',
            'manage company payout',
            'view sales incentive',
            'manage sales incentive master',

            // User & Customer
            'manage user',
            'manage customer',

            // Tracking
            'view tracking',
            'update tracking status',

            // Master Data
            'manage master data',
            'view promo master',
            'create promo master',
            'edit promo master',
            'delete promo master',
            'apply invoice promo',

            // Sistem
            'view activity log',
            'manage system',
            'manage roles',

            // Export
            'export invoice',
            'export packing list',
            'export omset',

            // Customer portal
            'upload payment proof',
            'rate delivery',
            'view own barang',
            'view own invoice',
        ];
    }

    /** @return list<string> */
    public static function dashboardPermissions(): array
    {
        return array_values(array_unique(array_merge(
            ['view dashboard', 'manage task', 'assign feedback'],
            DashboardWidgetPermissions::all(),
        )));
    }

    public static function ensureRegistered(): void
    {
        foreach (self::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
