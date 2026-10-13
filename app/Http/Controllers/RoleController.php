<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PermissionCatalog;
use App\Support\PermissionLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RoleController extends Controller
{
    /** @return array<string, array{icon: string, items: list<string>}> */
    private static function permissionGroups(): array
    {
        return [
            'Dashboard' => [
                'icon'  => 'bi-grid-1x2-fill',
                'items' => PermissionCatalog::dashboardPermissions(),
            ],
        'Marking' => [
            'icon'  => 'bi-bookmark-star-fill',
            'items' => ['view marking', 'view all marking', 'create marking', 'edit marking', 'delete marking', 'manage marking pricing', 'export marking'],
        ],
        'Barang' => [
            'icon'  => 'bi-box-seam-fill',
            'items' => [
                'view barang', 'create barang', 'edit barang', 'delete barang', 'export barang',
                'approve barang', 'reject barang', 'download barang invoice', 'edit barang product logistics',
                'view all notifications',
            ],
        ],
        'Container' => [
            'icon'  => 'bi-truck',
            'items' => ['view container', 'create container', 'edit container', 'delete container', 'load barang', 'unload barang'],
        ],
        'Keuangan — Invoice & Bayar' => [
            'icon'  => 'bi-receipt-cutoff',
            'items' => [
                'view invoice', 'create invoice', 'apply invoice promo',
                'approve payment', 'reject payment', 'export invoice',
                'edit rmb rate',
            ],
        ],
        'Keuangan — Pricing & Pengajuan' => [
            'icon'  => 'bi-lightning-charge-fill',
            'items' => [
                'view pricing', 'change price', 'submit repricing', 'approve repricing', 'view pengajuan',
            ],
        ],
        'Penghasilan Sales & Insentif' => [
            'icon'  => 'bi-wallet2',
            'items' => [
                'view sales revenue',
                'view sales incentive',
                'manage sales incentive master',
                'confirm sales commission',
                'mark sales commission cair',
                'upload sales salary proof',
                'manage company payout',
                'export omset',
            ],
        ],
        'Surat Jalan' => [
            'icon'  => 'bi-truck',
            'items' => [
                'view delivery trip', 'create delivery trip', 'manage delivery trip',
                'confirm delivery trip', 'export delivery trip pdf', 'delete delivery trip',
            ],
        ],
        'User & Customer' => [
            'icon'  => 'bi-people-fill',
            'items' => ['manage user', 'manage customer', 'manage roles'],
        ],
        'Tracking' => [
            'icon'  => 'bi-radar',
            'items' => ['view tracking', 'update tracking status'],
        ],
        'Master Data' => [
            'icon'  => 'bi-database-fill',
            'items' => [
                'manage master data',
                'view promo master',
                'create promo master',
                'edit promo master',
                'delete promo master',
            ],
        ],
        'Sistem' => [
            'icon'  => 'bi-gear-fill',
            'items' => ['view activity log', 'manage system'],
        ],
        'Export' => [
            'icon'  => 'bi-file-earmark-arrow-down-fill',
            'items' => ['export invoice', 'export packing list', 'export omset'],
        ],
        'Customer Portal' => [
            'icon'  => 'bi-person-badge-fill',
            'items' => ['upload payment proof', 'rate delivery', 'view own barang', 'view own invoice'],
        ],
        ];
    }

    public function index(): View
    {
        abort_unless(auth()->user()?->can('manage roles'), 403);

        PermissionCatalog::ensureRegistered();

        $roles = Role::with('permissions')->orderBy('name')->get();

        $allPermissions = Permission::orderBy('name')->get();

        $permissionMap = [];
        foreach ($allPermissions as $perm) {
            $permissionMap[$perm->name] = $perm->id;
        }

        $groups = [];
        foreach (self::permissionGroups() as $groupName => $groupData) {
            $items = [];
            foreach ($groupData['items'] as $permName) {
                if (isset($permissionMap[$permName])) {
                    $items[] = [
                        'id'    => $permissionMap[$permName],
                        'name'  => $permName,
                        'label' => PermissionLabel::for($permName),
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
        abort_unless(auth()->user()?->can('manage roles'), 403);

        $data = $request->validate([
            'permissions'   => 'present|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        $permissions = Permission::whereIn('id', $data['permissions'])->pluck('name');

        $role->syncPermissions($permissions);

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => __('messages.role_permissions_updated', ['name' => $role->name]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage roles'), 403);

        $data = $request->validate([
            'name' => 'required|string|max:50|unique:roles,name|regex:/^[a-z_]+$/',
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);

        return response()->json([
            'success' => true,
            'message' => __('messages.role_created', ['name' => $role->name]),
            'role'    => [
                'id'          => $role->id,
                'name'        => $role->name,
                'label'       => ucfirst(str_replace('_', ' ', $role->name)),
                'permissions' => [],
            ],
        ]);
    }
}
