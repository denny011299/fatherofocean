<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SalesCommissionSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

final class UserController extends Controller
{
    public function index(): View
    {
        $roles = Role::orderBy('name')->pluck('name');
        $commissionDefaults = SalesCommissionSettings::current();

        return view('user.index', compact('roles', 'commissionDefaults'));
    }

    public function data(): JsonResponse
    {
        $query = User::query()
            ->listedInUserManagement()
            ->with('roles:id,name');

        if (User::subordinatesTableReady()) {
            $query->withCount('subordinates');
        }

        $users = $query
            ->orderBy('name')
            ->get()
            ->map(function (User $u) {
                return [
                    'id'           => $u->id,
                    'name'         => $u->name,
                    'raw_name'     => $u->name,
                    'username'     => $u->username,
                    'raw_username' => $u->username,
                    'email'        => $u->email,
                    'phone'        => $u->phone,
                    'role'         => $u->roles->first()?->name ?? '-',
                    'role_label'   => self::formatRoleName($u->roles->first()?->name ?? '-'),
                    'commission_rate_override' => $u->commissionRateOverride(),
                    'commission_rate_label' => self::commissionTableLabel($u),
                    'created_at'   => $u->created_at?->format('d M Y'),
                    'is_self'      => $u->id === auth()->id(),
                    'subordinate_count' => (int) ($u->subordinates_count ?? 0),
                ];
            });

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users|alpha_dash',
            'email'    => 'nullable|email|unique:users',
            'phone'    => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'role'     => 'required|string|exists:roles,name',
            'commission_rate_override' => 'nullable|numeric|min:0|max:100',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'] ?? null,
            'phone'    => $data['phone'] ?? null,
            'password' => $data['password'],
            'sales_commission_rate_override' => self::normalizeCommissionOverride(
                $data['role'],
                $data['commission_rate_override'] ?? null,
            ),
        ]);

        $user->assignRole($data['role']);

        return response()->json([
            'success' => true,
            'message' => "User {$user->name} berhasil ditambahkan.",
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users')->ignore($user->id)],
            'email'    => ['nullable', 'email', Rule::unique('users')->ignore($user->id)],
            'phone'    => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
            'role'     => 'required|string|exists:roles,name',
            'commission_rate_override' => 'nullable|numeric|min:0|max:100',
        ]);

        $user->update([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'] ?? null,
            'phone'    => $data['phone'] ?? null,
            'sales_commission_rate_override' => self::normalizeCommissionOverride(
                $data['role'],
                $data['commission_rate_override'] ?? null,
            ),
        ]);

        if (!empty($data['password'])) {
            $user->update(['password' => $data['password']]);
        }

        $user->syncRoles([$data['role']]);

        return response()->json([
            'success' => true,
            'message' => "User {$user->name} berhasil diperbarui.",
        ]);
    }

    private static function formatRoleName(string $role): string
    {
        $map = [
            'super_admin'       => 'Super Admin',
            'supervisor'        => 'Supervisor',
            'admin_cs'          => 'Admin CS',
            'sales'             => 'Sales',
            'finance'           => 'Finance',
            'admin_gudang_china' => 'Admin Gudang China',
            'admin_gudang_indo' => 'Admin Gudang Indo',
            'customer'          => 'Customer',
        ];

        return $map[$role] ?? ucwords(str_replace('_', ' ', $role));
    }

    private static function commissionTableLabel(User $user): string
    {
        if (! $user->hasRole('sales')) {
            return '-';
        }

        $rate = SalesCommissionSettings::rateForSalesUser($user);
        $formatted = number_format($rate, 2, ',', '.');

        if ($user->hasCommissionRateOverride()) {
            return __('user.commission_custom_label', ['rate' => $formatted]);
        }

        return __('user.commission_general_label', ['rate' => $formatted]);
    }

    private static function normalizeCommissionOverride(string $role, mixed $value): ?float
    {
        if ($role !== 'sales') {
            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return max(0.0, min(100.0, (float) $value));
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Tidak bisa menghapus diri sendiri.'], 403);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => "User berhasil dihapus.",
        ]);
    }

    public function subordinates(User $user): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage user'), 403);

        if (! User::subordinatesTableReady()) {
            return response()->json([
                'options' => [],
                'selected' => [],
                'subordinates' => [],
                'subordinate_count' => 0,
            ]);
        }

        $user->loadMissing('subordinates:id,name,username');
        $selected = $user->subordinates->pluck('id')->map(static fn ($id) => (int) $id)->all();

        $options = User::query()
            ->listedInUserManagement()
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'username'])
            ->map(static fn (User $candidate) => [
                'id' => (int) $candidate->id,
                'text' => trim($candidate->name.' (@'.$candidate->username.')'),
            ])
            ->values()
            ->all();

        return response()->json([
            'options' => $options,
            'selected' => $selected,
            'subordinates' => $user->subordinates->map(static fn (User $sub) => [
                'id' => (int) $sub->id,
                'name' => (string) $sub->name,
                'username' => (string) $sub->username,
            ])->values()->all(),
            'subordinate_count' => count($selected),
        ]);
    }

    public function syncSubordinates(Request $request, User $user): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage user'), 403);
        abort_unless(User::subordinatesTableReady(), 422);

        $data = $request->validate([
            'subordinate_ids' => ['nullable', 'array'],
            'subordinate_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $ids = array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, $data['subordinate_ids'] ?? []),
            static fn (int $id): bool => $id > 0 && $id !== (int) $user->id,
        ));

        $user->subordinates()->sync($ids);

        return response()->json([
            'success' => true,
            'message' => __('user.subordinates_saved'),
            'subordinate_count' => count($ids),
        ]);
    }
}
