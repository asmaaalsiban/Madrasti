<?php

declare(strict_types=1);

namespace App\Modules\Grading\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Grading\Services\StudentTranscript;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The published grades of one student for the current academic year, by term and subject, with the result of each
 * subject: pass, fail, waiting for a makeup exam (was absent), waiting for a retake (failed the exam) or nothing graded.
 */
class StudentGradesController extends Controller
{
    public function __invoke(Student $student, StudentTranscript $transcript): JsonResponse
    {
        Gate::authorize('view-student-grades', $student);

        $year = AcademicYear::where('is_current', true)->first();
        $enrollment = $year ? $student->enrollments()->where('academic_year_id', $year->id)->with('classroom')->first() : null;

        $terms = $transcript->terms($student, $year, $enrollment);

        return response()->json(['data' => [
            'student' => $student->only(['id', 'student_number', 'name_ar', 'name_en']) + ['classroom' => $enrollment?->classroom?->name],
            'pass_percent' => (float) config('grading.pass_percent'),
            'terms' => $terms,
        ]]);
    }
}
