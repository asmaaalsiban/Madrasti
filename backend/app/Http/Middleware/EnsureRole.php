<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Shared\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:admin,teacher')
 * Allows the request only if the authenticated user has one of the given roles.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $allowed = array_values(array_filter(array_map(
            static fn (string $role): ?Role => Role::tryFrom($role),
            $roles
        )));

        if (! $user instanceof User || ! $user->hasRole(...$allowed)) {
            abort(403, __('auth.forbidden'));
        }

        return $next($request);
    }
}
