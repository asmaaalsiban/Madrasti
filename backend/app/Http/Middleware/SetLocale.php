<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the API language: Accept-Language header (ar/en),
 * otherwise the school's default language, otherwise the app default.
 */
class SetLocale
{
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));

        $locale = in_array($requested, self::SUPPORTED, true)
            ? $requested
            : (tenant('default_locale') ?: config('app.locale'));

        app()->setLocale($locale);

        return $next($request);
    }
}
