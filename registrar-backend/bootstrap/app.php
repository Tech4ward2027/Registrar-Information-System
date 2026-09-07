<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ── Cookie encryption exclusion ──────────────────────────────────────
        // The 'token' cookie holds a raw plain-text Sanctum personal-access
        // token and must NOT be decrypted by EncryptCookies.
        //
        // WHY app/Http/Kernel.php IS NOT ENOUGH
        // Laravel 11 does not load Kernel.php at all — it's a no-op legacy
        // file from Laravel 10.  The framework's default EncryptCookies has no
        // $except list, so without this line it tries to decrypt the raw
        // 'token' cookie on every request, throws DecryptException
        // ("The payload is invalid."), which is a RuntimeException, and
        // SsoCallbackController's catch(\RuntimeException) converts it to a
        // 403 — making every login fail for admins, superadmins, and any
        // student who has a prior session cookie.
        //
        // $middleware->encryptCookies() is the correct Laravel 11 API for this.
        // App\Http\Middleware\EncryptCookies ($except = ['token']) is kept for
        // documentation but has no runtime effect on its own in L11.
        $middleware->encryptCookies(except: ['token']);

        // ── Reverse-proxy trust ──────────────────────────────────────────────
        // The app runs behind an nginx TLS terminator.  Without this, Laravel
        // never sees X-Forwarded-Proto: https, so:
        //   • request()->secure() returns false
        //   • Secure cookies set by the backend are not sent back by the browser
        //     (browser rejects a non-Secure Set-Cookie over what it believes is
        //     plain HTTP), causing every /api/me call after login to return 401.
        //   • APP_URL-based helpers generate http:// URLs.
        $middleware->trustProxies(at: '*');

        // ── Cookie → Bearer token bridge ────────────────────────────────────
        // Login stores the Sanctum token in an HttpOnly cookie named "token".
        // Sanctum's token guard only reads Authorization: Bearer headers, so
        // without this the cookie is invisible to Sanctum and every request
        // returns 401.  This middleware runs first on all API routes and
        // promotes the cookie value into the header so Sanctum finds it.
        $middleware->prependToGroup('api', \App\Http\Middleware\AuthenticateFromCookie::class);

        // ── Route middleware aliases ─────────────────────────────────────────
        $middleware->alias([
            'role'   => \App\Http\Middleware\RoleMiddleware::class,
            // Fine-grained policy enforcement on top of 'role' — see
            // EnsureModuleAccess docblock for how the two interact.
            'module' => \App\Http\Middleware\EnsureModuleAccess::class,
            // Re-checks status on every request (not just at login) — see
            // EnsureAccountActive docblock. Applied to the shared protected
            // route group in routes/api.php, not globally here, since public
            // routes (login, announcements) have no authenticated user yet.
            'active' => \App\Http\Middleware\EnsureAccountActive::class,
            // Route-layer config/features.php flag gate — see
            // EnsureFeatureEnabled docblock for how this differs from
            // 'module' and why it 404s rather than 403s. Applied only to
            // routes that opt into a specific flag (e.g.
            // 'feature:free_request_page'), never globally.
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,
        ]);

        // ── Auth redirect behaviour ──────────────────────────────────────────
        // Return 401 JSON for API routes instead of redirecting to a login page.
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null; // null = throw AuthenticationException → 401
            }
            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return true; // always return JSON, never HTML error pages
        });
    })->create();