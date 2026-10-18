<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Models\SuperAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class CentralAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = SuperAdmin::where('email', $data['email'])->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        return response()->json([
            'token' => $admin->createToken('central')->plainTextToken,
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
