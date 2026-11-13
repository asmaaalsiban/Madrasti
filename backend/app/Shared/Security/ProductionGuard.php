<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/** Settings that must be safe in production whatever the .env says. */
final class ProductionGuard
{
    public static function apply(Application $app): void
    {
        // Debug mode shows stack traces, queries and paths to everybody: in production it is never allowed.
        if ($app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
            Log::critical('APP_DEBUG is on in production; it was forced off. Set APP_DEBUG=false in the .env.');
        }
        if (config('app.force_https')) {
            URL::forceScheme('https'); // every link and file address the API builds is https
        }
    }
}
