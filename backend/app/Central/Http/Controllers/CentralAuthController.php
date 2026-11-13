<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Models\SuperAdmin;
use App\Central\Services\AdminLoginCode;
use App\Http\Controllers\Controller;
use App\Shared\Security\LoginMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class CentralAuthController extends Controller
{
    /** A bcrypt hash of a random string, compared against when the email is unknown. */
    private const DUMMY_HASH = '$2y$12$pkajn8fhUs1.Vc/8lrK.qeYtZYdHU/9sldbw4OgJtMtfpDE9lwY3S';

    /** Step 1: password. Answers the same challenge for an unknown email (no mail); only a wrong password for a real account fails. */
    public function login(Request $request, AdminLoginCode $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = SuperAdmin::where('email', $data['email'])->first();

        if (! $admin) {
            Hash::check($data['password'], self::DUMMY_HASH); // Same cost as a real check, so timing does not reveal the email.

            return response()->json(['challenge' => true]);
        }
        if (! Hash::check($data['password'], $admin->password)) {
            LoginMonitor::failed('central', $data['email'], $request->ip());
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        $codes->issue($admin);

        return response()->json(['challenge' => true]);
    }

    /** Step 2: the e-mailed code. One answer for an unknown email, a wrong, expired, locked or used code. */
    public function verify(Request $request, AdminLoginCode $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $admin = SuperAdmin::where('email', $data['email'])->first();

        if (! $admin || ! $codes->verify($admin, $data['code'])) {
            LoginMonitor::failed('central', $data['email'], $request->ip(), 'code');
            throw ValidationException::withMessages(['code' => [__('auth.admin_code_invalid')]]);
        }

        return response()->json([
            'token' => $admin->createToken('central', ['*'], now()->addHours(8))->plainTextToken,
            'user' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
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

    public function me(Request $request): JsonResponse
    {
        $admin = $request->user();

        return response()->json(['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email]);
    }
}
