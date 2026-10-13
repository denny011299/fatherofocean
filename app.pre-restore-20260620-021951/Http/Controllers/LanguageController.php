<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class LanguageController extends Controller
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! Locales::isSupported($locale)) {
            abort(404);
        }

        $request->session()->put('locale', $locale);

        $fallback = auth()->check() ? route('dashboard') : route('login');

        return redirect()
            ->back(fallback: $fallback)
            ->withCookie(Cookie::make('locale', $locale, 60 * 24 * 365));
    }
}
