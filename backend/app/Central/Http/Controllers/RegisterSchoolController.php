<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Actions\CreateSchool;
use App\Central\Http\Requests\RegisterSchoolRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Public self-service onboarding: creates a school and its first admin.
 */
class RegisterSchoolController extends Controller
{
    public function __invoke(RegisterSchoolRequest $request, CreateSchool $createSchool): JsonResponse
    {
        // Creating a database and running every migration can take a while.
        set_time_limit(300);

        $code = $request->validated('code');

        try {
            $createSchool->handle([
                'id' => $code,
                'name' => $request->validated('school_name'),
                'admin_name' => $request->validated('admin_name'),
                'admin_email' => $request->validated('admin_email'),
                'admin_password' => $request->validated('password'),
                'default_locale' => app()->getLocale(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('registration.failed')], 500);
        }

        return response()->json(['data' => ['code' => $code]], 201);
    }
}
