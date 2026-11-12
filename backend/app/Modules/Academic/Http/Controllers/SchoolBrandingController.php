<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Central\Support\SchoolState;
use App\Central\Support\SchoolStatus;
use App\Http\Controllers\Controller;
use App\Shared\Branding\SchoolBranding;
use Illuminate\Http\JsonResponse;

/**
 * Public endpoint: the frontend is one build for all schools, so it asks
 * "which school is this?" by domain and gets name, logo and colors.
 */
class SchoolBrandingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $school = tenant();

        abort_if($school === null, 404, __('tenancy.school_not_found'));

        // The school's own logo and colours (set by its principal from the logo) win; otherwise what the platform gave it at registration.
        $own = app(SchoolBranding::class)->publicData();

        $state = SchoolStatus::of($school);

        return response()->json([
            'name' => $school->name,
            'logo_url' => $own['logo_url'] ?? $school->logo_path,
            'primary_color' => $own['primary_color'] ?? $school->primary_color,
            'dark_color' => $own['dark_color'],
            'default_locale' => $school->default_locale ?? config('app.locale'),
            'suspended' => $state !== SchoolState::Active,
            'exit_window_ends_at' => $state === SchoolState::ExitWindow ? $school->exit_window_ends_at?->toIso8601String() : null,
        ]);
    }
}
