<?php

use App\Http\Middleware\AdminIdleTimeout;
use App\Http\Middleware\EnsureNotBanned;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureNotBanned::class,
        ]);

        $middleware->validateCsrfTokens(except: ['telegram/webhook']);

        $middleware->alias([
            'role' => EnsureRole::class,
            '2fa.confirmed' => EnsureTwoFactorConfirmed::class,
            'admin.idle' => AdminIdleTimeout::class,
        ]);

        // Admins sign in at /admin/login (Fortify); creators use the Telegram widget at /login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*') ? '/admin/login' : '/login');

        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->isAdmin() ? '/admin' : '/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Friendly Inertia error pages (404 keeps a search bar, plan §9.8).
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $handled = [403, 404, 419, 429, 503];

            if (! app()->hasDebugModeEnabled()) {
                $handled[] = 500;
            }

            if (! in_array($status, $handled, true) || $request->expectsJson() || $request->is('api/*')) {
                return $response;
            }

            if ($status === 419) {
                return back()->with('status', 'The page expired, please try again.');
            }

            return Inertia::render('public/error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
