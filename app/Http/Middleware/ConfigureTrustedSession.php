<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Align session cookie flags with the real client scheme (Cloudflare / reverse proxy).
 * Wrong secure flag is a common cause of 419 on login.
 */
final class ConfigureTrustedSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requestIsHttps($request)) {
            config(['session.secure' => true]);
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
