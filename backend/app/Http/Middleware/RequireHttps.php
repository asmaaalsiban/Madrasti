<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends plain HTTP requests to HTTPS when `app.force_https` is on (the default in production). Behind a proxy or load balancer the
 * request is HTTPS for the proxy and HTTP for us, so a request the proxy says was HTTPS (X-Forwarded-Proto) is let through even when
 * TRUSTED_PROXIES is not set: that only decides whether to redirect, never whether a request is trusted, so there is no redirect loop.
 */
class RequireHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        $secure = $request->isSecure() || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';
        if (config('app.force_https') && ! $secure) {
            return redirect()->secure($request->getRequestUri(), 308);
        }

        return $next($request);
    }
}
