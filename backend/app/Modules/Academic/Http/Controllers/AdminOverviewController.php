<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use Illuminate\Http\JsonResponse;

class AdminOverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'academic_years' => AcademicYear::count(),
            'grade_levels' => GradeLevel::count(),
            'classrooms' => Classroom::count(),
            'subjects' => Subject::count(),
        ]]);
    }
}
