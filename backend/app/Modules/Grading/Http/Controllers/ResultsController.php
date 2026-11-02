<?php

declare(strict_types=1);

namespace App\Modules\Grading\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Grading\Services\SubjectResults;
use App\Shared\Enums\Role;
use App\Shared\Export\XlsxExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Pass / fail of every student of a classroom in a subject and term (teacher of the subject, or admin). */
class ResultsController extends Controller
{
    public function __invoke(Request $request, SubjectResults $results): JsonResponse
    {
        [$classroom, $subject, $term] = $this->authorized($request);

        return response()->json(['data' => [
            'pass_percent' => (float) config('grading.pass_percent'),
            'students' => $results->forClassroom($classroom, $subject, $term)->values(),
        ]]);
    }

    /** The same results as an Excel file. */
    public function export(Request $request, SubjectResults $results, XlsxExport $xlsx): BinaryFileResponse
    {
        [$classroom, $subject, $term] = $this->authorized($request);
        $rows = $results->forClassroom($classroom, $subject, $term)->map(fn (array $r) => [
            $r['student']['student_number'], $r['student']['name_ar'], $r['student']['name_en'], $r['percent'],
            $r['passed'] === null ? '' : __($r['passed'] ? 'reports.passed' : 'reports.failed'), $r['graded'], $r['pending'], $r['retake'],
        ]);

        return $xlsx->download(__('reports.results_file').'_'.$classroom.'_'.$subject.'_'.$term, __('reports.results_sheet'), [
            __('reports.student_number'), __('reports.name_ar'), __('reports.name_en'), __('reports.percent'), __('reports.result'),
            __('reports.graded'), __('reports.pending'), __('reports.retake'),
        ], $rows);
    }

    /** @return array{0: int, 1: int, 2: int} classroom, subject, term the signed-in user may read */
    private function authorized(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $ids = array_map(fn (string $key) => $request->query($key), ['classroom_id', 'subject_id', 'term_id']);
        // Anything unusable answers 403, like an unknown classroom elsewhere: nobody can probe which ids exist.
        abort_unless(collect($ids)->every(fn ($id) => is_numeric($id)), 403);
        [$classroom, $subject, $term] = array_map('intval', $ids);

        $teaches = TeachingAssignment::where('classroom_id', $classroom)->where('subject_id', $subject)->where('teacher_id', $user->id)->exists();
        abort_unless($user->hasRole(Role::Admin) || ($user->hasRole(Role::Teacher) && $teaches), 403);

        return [$classroom, $subject, $term];
    }
}
