<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect requests to the canonical host/scheme from APP_URL.
 * Prevents 419 CSRF errors when users mix www and non-www hostnames.
 */
final class ForceAppUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = (string) config('app.url');

        if ($appUrl === '' || $appUrl === 'http://localhost') {
            if (app()->environment('local')) {
                return $next($request);
            }

            if (! $request->secure() && $this->requestIsHttps($request)) {
                return redirect()->secure($request->getRequestUri(), Response::HTTP_MOVED_PERMANENTLY);
            }

            return $next($request);
        }

        $parsed = parse_url($appUrl);
        $expectedHost = $parsed['host'] ?? null;
        $expectedScheme = $parsed['scheme'] ?? 'https';

        if ($expectedHost !== null && $request->getHost() !== $expectedHost) {
            $target = $expectedScheme.'://'.$expectedHost.$request->getRequestUri();

            return redirect()->to($target, Response::HTTP_MOVED_PERMANENTLY);
        }

        if ($expectedScheme === 'https' && ! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), Response::HTTP_MOVED_PERMANENTLY);
        }

        return $next($request);
    }

    private function requestIsHttps(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        $forwarded = strtolower((string) $request->header('X-Forwarded-Proto', ''));

        return in_array($forwarded, ['https', 'on', '1'], true);
    }
}
