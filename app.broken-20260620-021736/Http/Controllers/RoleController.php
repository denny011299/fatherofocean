<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RoleController extends Controller
{
    private const PERMISSION_GROUPS = [
        'Dashboard' => [
            'icon'  => 'bi-grid-1x2-fill',
            'items' => ['view dashboard', 'manage task', 'assign feedback'],
        ],
        'Marking' => [
            'icon'  => 'bi-bookmark-star-fill',
            'items' => ['view marking', 'create marking', 'edit marking', 'delete marking', 'manage marking pricing'],
        ],
        'Barang' => [
            'icon'  => 'bi-box-seam-fill',
            'items' => ['view barang', 'create barang', 'edit barang', 'delete barang', 'import barang'],
        ],
        'Container' => [
            'icon'  => 'bi-truck',
            'items' => ['view container', 'create container', 'edit container', 'delete container', 'load barang', 'unload barang'],
        ],
        'Keuangan' => [
            'icon'  => 'bi-cash-stack',
            'items' => ['view invoice', 'create invoice', 'change price', 'approve payment', 'reject payment', 'view pricing'],
        ],
        'User & Customer' => [
            'icon'  => 'bi-people-fill',
            'items' => ['manage user', 'manage customer'],
        ],
        'Tracking' => [
            'icon'  => 'bi-radar',
            'items' => ['view tracking', 'update tracking status'],
        ],
        'Master Data' => [
            'icon'  => 'bi-database-fill',
            'items' => ['manage master data'],
        ],
        'Sistem' => [
            'icon'  => 'bi-gear-fill',
            'items' => ['view activity log', 'manage system'],
        ],
        'Export' => [
            'icon'  => 'bi-file-earmark-arrow-down-fill',
            'items' => ['export invoice', 'export packing list'],
        ],
        'Customer Portal' => [
            'icon'  => 'bi-person-badge-fill',
            'items' => ['upload payment proof', 'rate delivery', 'view own barang', 'view own invoice'],
        ],
    ];

    public function index(): View
    {
        $roles = Role::with('permissions')->orderBy('name')->get();

        $allPermissions = Permission::orderBy('name')->get();

        $permissionMap = [];
        foreach ($allPermissions as $perm) {
            $permissionMap[$perm->name] = $perm->id;
        }

        $groups = [];
        foreach (self::PERMISSION_GROUPS as $groupName => $groupData) {
            $items = [];
            foreach ($groupData['items'] as $permName) {
                if (isset($permissionMap[$permName])) {
                    $items[] = [
                        'id'   => $permissionMap[$permName],
                        'name' => $permName,
                    ];
                }
            }
            if ($items !== []) {
                $groups[] = [
                    'name'  => $groupName,
                    'icon'  => $groupData['icon'],
                    'items' => $items,
                ];
            }
        }

        $rolesData = $roles->map(fn (Role $role) => [
            'id'          => $role->id,
            'name'        => $role->name,
            'label'       => ucfirst(str_replace('_', ' ', $role->name)),
            'permissions' => $role->permissions->pluck('id')->toArray(),
        ]);

        return view('role.index', compact('rolesData', 'groups'));
    }

    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'permissions'   => 'present|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        $permissions = Permission::whereIn('id', $data['permissions'])->pluck('name');

        $role->syncPermissions($permissions);

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => "Hak akses untuk role \"{$role->name}\" berhasil diperbarui.",
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:50|unique:roles,name|regex:/^[a-z_]+$/',
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);

        return response()->json([
            'success' => true,
            'message' => "Role \"{$role->name}\" berhasil dibuat.",
            'role'    => [
                'id'          => $role->id,
                'name'        => $role->name,
                'label'       => ucfirst(str_replace('_', ' ', $role->name)),
                'permissions' => [],
            ],
        ]);
    }
}
