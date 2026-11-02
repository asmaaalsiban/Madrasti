<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\ChangePasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\EmailVerification;
use App\Modules\Auth\Services\PasswordReset;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $login = $request->validated('login');

        $user = User::findByLogin($login);

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

    /** Sends the reset code (the same answer whether or not the account exists). */
    public function forgotPassword(Request $request, PasswordReset $reset): JsonResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255']]);
        $reset->request($data['login'], app()->getLocale());

        return response()->json(['sent' => true, 'message' => __('auth.reset_requested')]);
    }

    /** Sets the new password with the code. */
    public function resetPassword(Request $request, PasswordReset $reset): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed', Rule::notIn([config('accounts.default_password')])],
        ], ['password.not_in' => __('auth.password_is_default')]);

        if (! $reset->reset($data['login'], $data['code'], $data['password'])) {
            throw ValidationException::withMessages(['code' => [__('auth.reset_invalid')]]);
        }

        return response()->json(['message' => __('auth.reset_done')]);
    }

    /** A parent who signs in with a phone number asks to put an email on the account: a code is sent to it. */
    public function requestEmail(Request $request, EmailVerification $verification): JsonResponse
    {
        abort_unless($verification->eligible($request->user()), 403);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $verification->request($request->user(), $data['email'], app()->getLocale());

        return response()->json(['sent' => true, 'message' => __('auth.email_requested')]);
    }

    /** Confirms the code. After a merge the person continues as the account that already had the email (a new token is returned). */
    public function confirmEmail(Request $request, EmailVerification $verification): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'code' => ['required', 'string', 'digits:6']]);
        $before = $request->user();
        $user = $verification->confirm($before, $data['email'], $data['code']);
        if ($user === null) {
            throw ValidationException::withMessages(['code' => [__('auth.reset_invalid')]]);
        }

        $merged = $user->id !== $before->id;

        return response()->json([
            'merged' => $merged,
            'token' => $merged ? $user->createToken('web')->plainTextToken : null,
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

        if ($user->role === Role::Student) {
            $user->load('student.enrollments.classroom');
        }
        if ($user->role === Role::Teacher) {
            $user->load('staffProfile.subject');
        }

        return $user;
    }
}
