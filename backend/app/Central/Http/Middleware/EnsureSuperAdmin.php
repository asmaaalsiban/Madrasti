<?php

declare(strict_types=1);

namespace App\Central\Http\Middleware;

use App\Central\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof SuperAdmin) {
            abort(403, __('auth.forbidden'));
        }

        return $next($request);
    }
}
