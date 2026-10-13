<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Marking;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function showLogin(Request $request): View
    {
        if ($request->hasSession()) {
            $request->session()->regenerateToken();
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required',
        ]);

        $remember = $request->boolean('remember');

        // 1) Login normal by username/email (existing behavior)
        if (Auth::attempt($credentials, $remember)) {
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        // 2) Fallback: customer portal login with marking code + passcode
        $marking = Marking::findForCustomerLogin(
            (string) $credentials['username'],
            (string) $credentials['password'],
        );

        if ($marking !== null) {
            $user = $marking->customer;

            if ($user !== null) {
                Auth::login($user, $remember);
                $request->session()->regenerate();
                $request->session()->put('customer_marking_id', (int) $marking->id);
                $request->session()->put('customer_marking_code', $marking->displayCode());

                return redirect()->intended(route('dashboard'));
            }
        }

        return back()->withErrors(['username' => 'Username atau password salah.'])->onlyInput('username');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users|alpha_dash',
            'email' => 'nullable|email|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create($data);
        $user->assignRole('customer');

        Auth::login($user);

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->forget(['customer_marking_id', 'customer_marking_code']);

        return redirect()->route('login');
    }
}
