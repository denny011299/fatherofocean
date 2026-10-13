<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->permissionsTableExists()) {
            return;
        }

        $guard = 'web';
        $exportBarang = Permission::query()->where('name', 'export barang')->where('guard_name', $guard)->first();
        $importBarang = Permission::query()->where('name', 'import barang')->where('guard_name', $guard)->first();
        $exportProducts = Permission::query()->where('name', 'export barang products')->where('guard_name', $guard)->first();

        if ($exportBarang === null && $importBarang !== null) {
            $importBarang->update(['name' => 'export barang']);
            $exportBarang = $importBarang->fresh();
        }

        if ($exportBarang === null) {
            $exportBarang = Permission::query()->create([
                'name' => 'export barang',
                'guard_name' => $guard,
            ]);
        }

        $roleIds = collect();

        if ($importBarang !== null && (int) $importBarang->id !== (int) $exportBarang->id) {
            $roleIds = $roleIds->merge(
                DB::table('role_has_permissions')->where('permission_id', $importBarang->id)->pluck('role_id')
            );
            $importBarang->delete();
        }

        if ($exportProducts !== null) {
            $roleIds = $roleIds->merge(
                DB::table('role_has_permissions')->where('permission_id', $exportProducts->id)->pluck('role_id')
            );
            $exportProducts->delete();
        }

        foreach ($roleIds->unique() as $roleId) {
            $role = Role::query()->find($roleId);
            if ($role !== null && ! $role->hasPermissionTo('export barang')) {
                $role->givePermissionTo('export barang');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->permissionsTableExists()) {
            return;
        }

        $guard = 'web';
        $exportBarang = Permission::query()->where('name', 'export barang')->where('guard_name', $guard)->first();

        if ($exportBarang !== null) {
            $exportBarang->update(['name' => 'import barang']);
        }

        Permission::query()->firstOrCreate([
            'name' => 'export barang products',
            'guard_name' => $guard,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionsTableExists(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('permissions');
    }
};
