<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safe browser defaults on every API answer. The API only ever returns JSON, files and images, so it needs no scripts, frames or
 * other resources: a browser that opens one of its answers directly gets a locked-down page. (The pages of the website itself are
 * served by the web server in front of the frontend; see docs/security.md for the headers to set there.)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        // Only over a real HTTPS request: the browser then refuses plain HTTP to this site for a year.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
