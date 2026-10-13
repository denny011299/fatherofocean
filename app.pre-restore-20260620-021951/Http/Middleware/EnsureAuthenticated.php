<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $this->respondUnauthenticated($request);
        }

        return $next($request);
    }

    private function respondUnauthenticated(Request $request): Response
    {
        $message = __('auth.session_expired');
        $loginUrl = route('login');

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => $message,
                'redirect' => $loginUrl,
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->guest($loginUrl)->with('warning', $message);
    }
}
