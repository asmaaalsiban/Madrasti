<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\ChangePasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $login = $request->validated('login');

        $user = User::query()
            ->where(fn ($query) => $query->where('email', $login)->orWhere('phone', $login))
            ->first();

        // One generic message for unknown user and wrong password (no user enumeration).
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages(['login' => [__('auth.failed')]]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => [__('auth.inactive')]]);
        }

        // Suspended school (outside tenancy, e.g. in tests, tenant() is null).
        $school = tenant();
        if ($school !== null && ! $school->is_active) {
            throw ValidationException::withMessages(['login' => [__('auth.school_inactive')]]);
        }

        $token = $user->createToken($request->validated('device_name') ?? 'web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($this->withChildren($user)),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => __('auth.logged_out')]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($this->withChildren($request->user()));
    }

    public function changePassword(ChangePasswordRequest $request): UserResource
    {
        $user = $request->user();
        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
        ])->save();

        // Sign out every other device; keep the one that just changed the password.
        $current = $user->currentAccessToken();
        $tokens = $user->tokens();
        if ($current instanceof PersonalAccessToken) {
            $tokens->where('id', '!=', $current->id);
        }
        $tokens->delete();

        return new UserResource($this->withChildren($user));
    }

    private function withChildren(User $user): User
    {
        if ($user->role === Role::Guardian) {
            $user->load(['children.user', 'children.enrollments.classroom']);
        }

        if ($user->role === Role::Teacher) {
            $user->load('staffProfile');
        }

        return $user;
    }
}
