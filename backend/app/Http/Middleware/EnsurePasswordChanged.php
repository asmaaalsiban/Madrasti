<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts created by the admin start with a default password: until the user replaces it, the API
 * only answers auth/me, auth/logout and auth/change-password (those routes are not behind this middleware).
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password) {
            return response()->json([
                'message' => __('auth.password_change_required'),
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
