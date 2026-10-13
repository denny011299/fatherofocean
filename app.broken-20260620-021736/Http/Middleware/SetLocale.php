<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $default = (string) config('app.locale', 'id');

        $sessionLocale = session('locale');
        $cookieLocale = $request->cookie('locale');

        $locale = is_string($sessionLocale) && $sessionLocale !== ''
            ? $sessionLocale
            : (is_string($cookieLocale) && $cookieLocale !== '' ? $cookieLocale : $default);

        if (! Locales::isSupported($locale)) {
            $locale = $default;
        }

        App::setLocale($locale);

        return $next($request);
    }
}
