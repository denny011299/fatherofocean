<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Customer accounts use /tracking (scoped by marking), not staff tracking pages. */
final class RedirectCustomerFromStaffRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->hasRole('customer')) {
            return redirect()
                ->route('tracking.index')
                ->with('info', __('tracking.customer_redirect_from_staff'));
        }

        return $next($request);
    }
}
