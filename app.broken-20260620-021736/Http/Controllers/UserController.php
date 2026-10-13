<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
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

        return view('user.index', compact('roles'));
    }

    public function data(): JsonResponse
    {
        $users = User::query()
            ->listedInUserManagement()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(function ($u) {
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
                    'created_at'   => $u->created_at?->format('d M Y'),
                    'is_self'      => $u->id === auth()->id(),
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
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'] ?? null,
            'phone'    => $data['phone'] ?? null,
            'password' => $data['password'],
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
        ]);

        $user->update([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'] ?? null,
            'phone'    => $data['phone'] ?? null,
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
}
