<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
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

        return response()->json([
            'name' => $school->name,
            'logo_url' => $school->logo_path,
            'primary_color' => $school->primary_color,
            'default_locale' => $school->default_locale ?? config('app.locale'),
        ]);
    }
}
