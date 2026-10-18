<?php

use App\Central\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Super Admin API: only reachable on the central domains.
            // School APIs live in routes/tenant.php (loaded by TenancyServiceProvider).
            foreach (config('tenancy.central_domains') as $domain) {
                Route::middleware('api')
                    ->domain($domain)
                    ->prefix('api/central')
                    ->group(base_path('routes/central/api.php'));
            }
        },
    )
    ->withCommands([
        __DIR__.'/../app/Central/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        $middleware->api(append: [
            SetLocale::class,
        ]);
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
    })->create();
