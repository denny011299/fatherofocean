<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\DashboardWidgetPermissions;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = PermissionCatalog::all();

        foreach ($permissions as $perm) {
            Permission::query()->firstOrCreate(['name' => $perm]);
        }

        // Full-access roles get every permission in DB (including any added via UI later).
        $allPermissionNames = Permission::query()->orderBy('name')->pluck('name')->all();

        $superAdmin = Role::query()->firstOrCreate(['name' => 'super_admin']);
        $superAdmin->syncPermissions($allPermissionNames);

        $supervisor = Role::query()->firstOrCreate(['name' => 'supervisor']);
        $supervisor->syncPermissions($allPermissionNames);
        $supervisor->revokePermissionTo([
            'view harga modal',
            'edit harga modal',
            'view all marking',
            'edit rmb rate',
        ]);

        // ── admin_cs ──
        $adminCs = Role::query()->firstOrCreate(['name' => 'admin_cs']);
        $adminCs->syncPermissions([
            'view dashboard', 'manage task', 'assign feedback',
            ...DashboardWidgetPermissions::defaultAdminStaff(),
            'view marking', 'view all marking', 'create marking', 'edit marking', 'export marking',
            'manage user',
            'view barang', 'create barang', 'edit barang', 'approve barang', 'reject barang',
            'export barang', 'download barang invoice',
            'view container',
            'view bongkar muat', 'manage bongkar muat',
            'view delivery trip', 'create delivery trip', 'manage delivery trip',
            'confirm delivery trip', 'export delivery trip pdf', 'delete delivery trip',
            'view claim', 'create claim', 'approve claim', 'reject claim',
            'view tracking', 'update tracking status',
            'manage master data',
            'view promo master', 'create promo master', 'edit promo master', 'delete promo master',
            'export packing list',
            'view sales revenue', 'view sales incentive', 'confirm sales commission', 'mark sales commission cair', 'upload sales salary proof', 'manage company payout',
            'view all notifications',
        ]);

        // ── sales ──
        $sales = Role::query()->firstOrCreate(['name' => 'sales']);
        $sales->syncPermissions([
            'view dashboard', 'manage task',
            ...DashboardWidgetPermissions::defaultSales(),
            'view marking', 'export marking',
            'view barang', 'create barang', 'edit barang', 'export barang',
            'view tracking',
            'view claim', 'create claim',
            'view sales revenue', 'view sales incentive',
            'submit repricing', 'change price', 'view pengajuan',
        ]);

        // ── finance ──
        $finance = Role::query()->firstOrCreate(['name' => 'finance']);
        $finance->syncPermissions([
            'view dashboard',
            'edit rmb rate',
            'manage marking pricing', 'view pricing',
            'change price', 'submit repricing', 'approve repricing', 'view pengajuan',
            'view invoice', 'create invoice', 'apply invoice promo',
            'approve payment', 'reject payment',
            'view claim', 'approve claim', 'reject claim',
            'view tracking', 'view container',
            'view delivery trip', 'export delivery trip pdf',
            'export invoice', 'export omset', 'download barang invoice',
            'view sales revenue', 'view sales incentive', 'manage sales incentive master',
            'confirm sales commission', 'mark sales commission cair', 'upload sales salary proof', 'manage company payout',
            'view all notifications',
        ]);

        // ── admin_gudang_china ──
        $gudangChina = Role::query()->firstOrCreate(['name' => 'admin_gudang_china']);
        $gudangChina->syncPermissions([
            'view dashboard',
            ...DashboardWidgetPermissions::defaultGudangChina(),
            'view barang', 'create barang', 'edit barang', 'edit barang product logistics',
            'export barang', 'download barang invoice',
            'view container', 'create container', 'edit container', 'load barang', 'unload barang',
            'view bongkar muat', 'manage bongkar muat',
            'view delivery trip', 'create delivery trip', 'manage delivery trip',
            'confirm delivery trip', 'export delivery trip pdf',
            'view claim', 'approve claim', 'reject claim',
            'view tracking', 'update tracking status',
            'export packing list',
            'manage task',
            'view all notifications',
        ]);

        // ── admin_gudang_indo ──
        $gudangIndo = Role::query()->firstOrCreate(['name' => 'admin_gudang_indo']);
        $gudangIndo->syncPermissions([
            'view dashboard',
            'view barang',
            'view container',
            'view bongkar muat', 'manage bongkar muat',
            'view delivery trip', 'create delivery trip', 'manage delivery trip',
            'confirm delivery trip', 'export delivery trip pdf', 'delete delivery trip',
            'view claim', 'approve claim', 'reject claim',
            'view tracking', 'update tracking status',
            'view all notifications',
        ]);

        // ── customer ──
        $customer = Role::query()->firstOrCreate(['name' => 'customer']);
        $customer->syncPermissions([
            'view dashboard',
            'view own barang', 'view own invoice',
            'upload payment proof', 'rate delivery',
            'view claim', 'create claim',
        ]);

        $this->migrateLegacyDailyRatePermission();
        $this->grantDefaultDashboardWidgetsToExistingRoles();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grantDefaultDashboardWidgetsToExistingRoles(): void
    {
        $defaults = [
            'admin_cs' => DashboardWidgetPermissions::defaultAdminStaff(),
            'sales' => DashboardWidgetPermissions::defaultSales(),
            'admin_gudang_china' => DashboardWidgetPermissions::defaultGudangChina(),
            'super_admin' => DashboardWidgetPermissions::all(),
            'supervisor' => DashboardWidgetPermissions::all(),
        ];

        foreach ($defaults as $roleName => $widgets) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role !== null) {
                $role->givePermissionTo($widgets);
            }
        }

        $sales = Role::query()->where('name', 'sales')->first();
        if ($sales !== null) {
            $sales->givePermissionTo([
                DashboardWidgetPermissions::OMSET,
                DashboardWidgetPermissions::TOTAL_CBM,
            ]);
        }
    }

    private function migrateLegacyDailyRatePermission(): void
    {
        $legacy = Permission::query()->where('name', 'manage daily rate')->first();
        if ($legacy === null) {
            return;
        }

        $replacement = Permission::query()->where('name', 'edit rmb rate')->first();
        if ($replacement !== null) {
            $legacyRoleIds = DB::table('role_has_permissions')
                ->where('permission_id', $legacy->id)
                ->pluck('role_id');

            foreach ($legacyRoleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $replacement->id,
                    'role_id' => $roleId,
                ]);
            }

            $legacyUserIds = DB::table('model_has_permissions')
                ->where('permission_id', $legacy->id)
                ->where('model_type', 'App\Models\User')
                ->pluck('model_id');

            foreach ($legacyUserIds as $userId) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $replacement->id,
                    'model_type' => 'App\Models\User',
                    'model_id' => $userId,
                ]);
            }
        }

        DB::table('role_has_permissions')->where('permission_id', $legacy->id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $legacy->id)->delete();
        $legacy->delete();
    }
}
