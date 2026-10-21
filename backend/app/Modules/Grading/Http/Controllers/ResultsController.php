<?php

declare(strict_types=1);

namespace App\Modules\Grading\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Grading\Services\SubjectResults;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pass / fail of every student of a classroom in a subject and term (teacher of the subject, or admin). */
class ResultsController extends Controller
{
    public function __invoke(Request $request, SubjectResults $results): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ids = array_map(fn (string $key) => $request->query($key), ['classroom_id', 'subject_id', 'term_id']);
        // Anything unusable answers 403, like an unknown classroom elsewhere: nobody can probe which ids exist.
        abort_unless(collect($ids)->every(fn ($id) => is_numeric($id)), 403);
        [$classroom, $subject, $term] = array_map('intval', $ids);

        $teaches = TeachingAssignment::where('classroom_id', $classroom)->where('subject_id', $subject)->where('teacher_id', $user->id)->exists();
        abort_unless($user->hasRole(Role::Admin) || ($user->hasRole(Role::Teacher) && $teaches), 403);

        return response()->json(['data' => [
            'pass_percent' => (float) config('grading.pass_percent'),
            'students' => $results->forClassroom($classroom, $subject, $term)->values(),
        ]]);
    }
}
