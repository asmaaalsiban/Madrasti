<?php

use App\Central\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Central API (school registration, Super Admin). One public URL serves every school, so it is not
            // tied to a host name. School APIs live in routes/tenant.php (loaded by TenancyServiceProvider).
            Route::middleware('api')
                ->prefix('api/central')
                ->group(base_path('routes/central/api.php'));
        },
    )
    ->withCommands([
        __DIR__.'/../app/Central/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Every signed-in school route goes through this group: valid token, active account, password already
        // changed. Only auth/me, auth/logout and auth/change-password stay outside it. Add new routes to it.
        $middleware->group('school-user', [
            'auth:sanctum',
            EnsureAccountActive::class,
            EnsurePasswordChanged::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'password.changed' => EnsurePasswordChanged::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        // Dev: Vite proxy forwards the school's host in X-Forwarded-Host, so trust every proxy locally.
        // Elsewhere set TRUSTED_PROXIES (comma separated IPs/CIDRs); with none set no forwarded header is trusted,
        // otherwise anyone could spoof X-Forwarded-For and dodge the IP rate limits.
        $trustedProxies = env('TRUSTED_PROXIES', env('APP_ENV') === 'local' ? '*' : '');
        if ($trustedProxies !== '') {
            $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)));
        }
        $middleware->api(append: [
            SetLocale::class,
        ]);
        // Laravel runs authentication first; the language must be set before it so a 401 is in the right language too.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Our frontend only talks JSON to the API.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        // Unknown school domain -> clean 404 instead of a 500.
        $exceptions->render(function (TenantCouldNotBeIdentifiedException $e, Request $request) {
            return response()->json(['message' => __('tenancy.school_not_found')], 404);
        });

        // A database problem is explained in plain words (never the SQL): the database is down, or the action failed.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $down = preg_match('/\[(2002|2003|2006|2013)\]|server has gone away|Connection refused|actively refused/i', $e->getMessage()) === 1;

            return response()->json(['message' => __($down ? 'errors.database_down' : 'errors.database_error')], $down ? 503 : 500);
        });

        // The framework's own messages ("Server Error", "Too Many Attempts.", ...) follow the request language (lang/ar.json).
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response instanceof JsonResponse && is_string($message = $response->getData(true)['message'] ?? null)) {
                $translated = __($message);
                if ($translated !== $message) {
                    $response->setData([...$response->getData(true), 'message' => $translated]);
                }
            }

            return $response;
        });
    })->create();
