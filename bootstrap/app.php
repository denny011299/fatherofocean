<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->booting(function ($app): void {
        $app->useLangPath($app->basePath('lang'));
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Shared hosting / Cloudflare: trust X-Forwarded-* so HTTPS + session cookies work.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->web(prepend: [
            \App\Http\Middleware\ConfigureTrustedSession::class,
            \App\Http\Middleware\ForceAppUrl::class,
        ], append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\EnsureAuthenticated::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'redirect.customer.from.staff' => \App\Http\Middleware\RedirectCustomerFromStaffRoutes::class,
            'no.auth.cache' => \App\Http\Middleware\PreventAuthPageCache::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsJson = static fn (Request $request): bool => $request->expectsJson() || $request->ajax();

        $sessionExpiredJson = static fn (Request $request): Response => response()->json([
            'message' => __('auth.session_expired'),
            'redirect' => route('login'),
        ], Response::HTTP_UNAUTHORIZED);

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson, $sessionExpiredJson) {
            if ($wantsJson($request)) {
                return $sessionExpiredJson($request);
            }

            return null;
        });

        $renderSessionExpired = static function (Request $request) use ($wantsJson, $sessionExpiredJson) {
            if ($wantsJson($request)) {
                return $sessionExpiredJson($request);
            }

            return redirect()
                ->guest(route('login'))
                ->with('warning', __('auth.session_expired'));
        };

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($renderSessionExpired) {
            return $renderSessionExpired($request);
        });

        $exceptions->render(function (HttpException $e, Request $request) use ($renderSessionExpired) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            return $renderSessionExpired($request);
        });
    })->create();
