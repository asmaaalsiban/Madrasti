<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Developer tool for load testing: when QUERY_STATS=true (and the app is not in production) every API answer carries how many database
 * queries it ran and how long they took (X-Queries, X-Query-Ms), so a slow or N+1 endpoint is easy to find. Off by default.
 */
class QueryStats
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.query_stats')) {
            return $next($request);
        }
        $count = 0;
        $ms = 0.0;
        DB::listen(function ($q) use (&$count, &$ms): void {
            $count++;
            $ms += $q->time;
        });
        $response = $next($request);
        $response->headers->set('X-Queries', (string) $count);
        $response->headers->set('X-Query-Ms', (string) round($ms, 1));

        return $response;
    }
}
