<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class UserPasswordController extends Controller
{
    /** Puts a teacher/student/parent back on the default password (they must choose a new one at the next sign-in). */
    public function reset(User $user): JsonResponse
    {
        Gate::authorize('resetPassword', $user);

        $user->forceFill([
            'password' => config('accounts.default_password'),
            'must_change_password' => true,
        ])->save();
        $user->tokens()->delete();

        return response()->json(['message' => __('people.password_reset')]);
    }
}
