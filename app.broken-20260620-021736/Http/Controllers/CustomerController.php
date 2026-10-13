<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Marking;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CustomerController extends Controller
{
    public function index(): View
    {
        return view('customer.index');
    }

    public function data(): JsonResponse
    {
        $customers = User::role('customer')
            ->with('roles:id,name')
            ->withCount('markings')
            ->orderBy('name')
            ->get()
            ->map(function ($u) {
                return [
                    'id'             => $u->id,
                    'name'           => $u->name,
                    'raw_name'       => $u->name,
                    'username'       => $u->username,
                    'raw_username'   => $u->username,
                    'email'          => $u->email,
                    'raw_email'      => $u->email,
                    'phone'          => $u->phone,
                    'raw_phone'      => $u->phone,
                    'address'        => $u->address,
                    'raw_address'    => $u->address,
                    'markings_count' => $u->markings_count,
                    'created_at'     => $u->created_at?->format('d M Y'),
                ];
            });

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users|alpha_dash',
            'email'    => 'nullable|email|unique:users',
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string|max:500',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'] ?? null,
            'phone'    => $data['phone'] ?? null,
            'address'  => $data['address'] ?? null,
            'password' => $data['password'],
        ]);

        $user->assignRole('customer');

        return response()->json([
            'success' => true,
            'message' => "Customer {$user->name} berhasil ditambahkan.",
        ]);
    }

    public function update(Request $request, User $customer): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users')->ignore($customer->id)],
            'email'    => ['nullable', 'email', Rule::unique('users')->ignore($customer->id)],
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string|max:500',
            'password' => 'nullable|string|min:6',
        ]);

        $customer->update([
            'name'     => $data['name'],
            'username' => $data['username'],
            'email'    => $data['email'] ?? null,
            'phone'    => $data['phone'] ?? null,
            'address'  => $data['address'] ?? null,
        ]);

        if (!empty($data['password'])) {
            $customer->update(['password' => $data['password']]);
        }

        return response()->json([
            'success' => true,
            'message' => "Customer {$customer->name} berhasil diperbarui.",
        ]);
    }

    public function destroy(User $customer): JsonResponse
    {
        if ($customer->id === auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Tidak bisa menghapus diri sendiri.'], 403);
        }

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => "Customer berhasil dihapus.",
        ]);
    }
}
