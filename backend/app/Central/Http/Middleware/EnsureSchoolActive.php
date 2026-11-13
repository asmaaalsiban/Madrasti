<?php

declare(strict_types=1);

namespace App\Central\Http\Middleware;

use App\Central\Models\Tenant;
use App\Central\Support\SchoolState;
use App\Central\Support\SchoolStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a suspended, expired or closed school on every request, including people who are already signed in.
 * Runs after the school is initialised. In an exit window only sign-in, "who am I", sign-out and the data export stay open;
 * in every other blocked state only the public school answer and logo do.
 */
class EnsureSchoolActive
{
    /** Method and path (without leading slash) pairs that stay open in an exit window. */
    private const EXIT_WINDOW_ALLOWED = [
        ['GET', 'api/school'],
        ['POST', 'api/auth/login'],
        ['GET', 'api/auth/me'],
        ['POST', 'api/auth/logout'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $school = tenant();

        if (! $school instanceof Tenant) {
            return $next($request);
        }

        $state = SchoolStatus::of($school);

        if ($state === SchoolState::Active || $this->allowed($state, $request)) {
            return $next($request);
        }

        return $this->refuse();
    }

    private function allowed(SchoolState $state, Request $request): bool
    {
        $method = $request->getMethod();
        $path = trim($request->getPathInfo(), '/');

        // The school answer and its logo are public: the "school is blocked" page shows the school's branding.
        if ($method === 'GET' && in_array($path, ['api/school', 'api/school/logo'], true)) {
            return true;
        }

        if ($state !== SchoolState::ExitWindow) {
            return false;
        }

        // On a path-segment boundary: "api/exit-exportX" is a different path.
        if ($path === 'api/exit-export' || str_starts_with($path, 'api/exit-export/')) {
            return true;
        }

        return in_array([$method, $path], self::EXIT_WINDOW_ALLOWED, true);
    }

    private function refuse(): JsonResponse
    {
        return response()->json([
            'message' => __('auth.school_suspended'),
            'code' => 'school_suspended',
        ], 403);
    }
}
