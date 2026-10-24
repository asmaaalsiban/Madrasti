<?php

declare(strict_types=1);

namespace App\Modules\Grading\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Grading\Http\Requests\AssessmentRequest;
use App\Modules\Grading\Http\Requests\SaveScoresRequest;
use App\Modules\Grading\Http\Resources\AssessmentResource;
use App\Modules\Grading\Models\Assessment;
use App\Modules\Grading\Models\AssessmentScore;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Assessments (quizzes, exams...) and the scores of their students, entered by the teacher of the subject. */
class AssessmentController extends Controller
{
    private const WITH = ['classroom', 'subject', 'term', 'teacher'];

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Assessment::query()->with(self::WITH)
            // Only students who still exist: the score of a deleted student must not make the progress exceed the class size.
            ->withCount(['scores as scored_count' => fn ($q) => $q->whereNotNull('score')->whereHas('student')])
            ->withCount(['scores as absent_count' => fn ($q) => $q->whereIn('status', ['absent', 'retake'])->whereNull('score')->whereHas('student')])
            ->when(! $user->hasRole(Role::Admin), fn ($q) => $q->where('teacher_id', $user->id))
            ->orderByDesc('held_on')->orderByDesc('id');
        foreach (['classroom_id', 'subject_id', 'term_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, (int) $request->query($column));
            }
        }

        $rows = $query->limit(300)->get();
        $students = Enrollment::whereIn('classroom_id', $rows->pluck('classroom_id')->unique())->whereHas('student')
            ->selectRaw('classroom_id, count(*) as n')->groupBy('classroom_id')->pluck('n', 'classroom_id');
        $rows->each(fn (Assessment $a) => $a->setAttribute('students_count', (int) ($students[$a->classroom_id] ?? 0)));

        return response()->json(['data' => AssessmentResource::collection($rows)->resolve()]);
    }

    public function store(AssessmentRequest $request): JsonResponse
    {
        $teacherId = $request->assignment()?->teacher_id ?? $request->user()->id;
        $assessment = Assessment::create([...$request->validated(), 'teacher_id' => $teacherId, 'is_published' => false]);

        return (new AssessmentResource($assessment->load(self::WITH)))->response()->setStatusCode(201);
    }

    /** The score sheet: every student of the classroom with their score. */
    public function show(Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage-assessment', $assessment);

        $scores = $assessment->scores()->get()->keyBy('student_id');
        $students = Enrollment::where('classroom_id', $assessment->classroom_id)
            ->join('students', 'students.id', '=', 'enrollments.student_id')->whereNull('students.deleted_at')
            ->orderBy('students.name_ar')->get(['students.id', 'students.student_number', 'students.name_ar', 'students.name_en']);

        $absentThatDay = $assessment->held_on === null ? collect() : DB::table('student_attendances')
            ->where('date', $assessment->held_on->format('Y-m-d'))->where('status', 'absent')->whereIn('student_id', $students->pluck('id'))->pluck('student_id');

        return response()->json(['data' => [
            'assessment' => (new AssessmentResource($assessment->load(self::WITH)))->resolve(),
            'students' => $students->map(fn ($s) => [
                'id' => $s->id, 'student_number' => $s->student_number, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en,
                'score' => $scores->get($s->id)?->score,
                'status' => $scores->get($s->id)?->status,
                'first_score' => $scores->get($s->id)?->first_score,
                'remark' => $scores->get($s->id)?->remark,
                // Hint for the teacher: the attendance of that day says the student was not at school.
                'was_absent' => $absentThatDay->contains($s->id),
            ])->values(),
            'pass_percent' => (float) config('grading.pass_percent'),
        ]]);
    }

    public function update(AssessmentRequest $request, Assessment $assessment): AssessmentResource
    {
        $assessment->update($request->validated());

        return new AssessmentResource($assessment->load(self::WITH));
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage-assessment', $assessment);
        abort_if($assessment->is_published, 422, __('grading.unpublish_first'));
        $assessment->delete();

        return response()->json(null, 204);
    }

    public function scores(SaveScoresRequest $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage-assessment', $assessment);

        DB::transaction(function () use ($request, $assessment): void {
            foreach ($request->validated('scores') as $row) {
                $score = isset($row['score']) && $row['score'] !== '' ? $row['score'] : null;
                $remark = ($row['remark'] ?? '') !== '' ? $row['remark'] : null;
                $status = $row['status'] ?? null;
                $existing = AssessmentScore::where('assessment_id', $assessment->id)->where('student_id', $row['student_id'])->first();

                if ($score === null && $remark === null && $status === null) {
                    $existing?->delete(); // cleared

                    continue;
                }
                // Marked for a retake: the score he had (the failing one) is kept as his first attempt.
                $first = $existing?->first_score;
                if ($status === 'retake' && $existing?->status !== 'retake' && $existing?->score !== null) {
                    $first = $existing->score;
                }
                AssessmentScore::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'student_id' => $row['student_id']],
                    ['score' => $score, 'first_score' => $first, 'status' => $status, 'remark' => $remark],
                );
            }
        });

        return response()->json(['message' => 'ok']);
    }

    public function publish(Assessment $assessment): AssessmentResource
    {
        return $this->setPublished($assessment, true);
    }

    public function unpublish(Assessment $assessment): AssessmentResource
    {
        return $this->setPublished($assessment, false);
    }

    private function setPublished(Assessment $assessment, bool $published): AssessmentResource
    {
        Gate::authorize('manage-assessment', $assessment);
        $wasPublished = $assessment->is_published;
        $assessment->update(['is_published' => $published]);
        if ($published && ! $wasPublished) {
            $assessment->loadMissing('subject');
            app(Notifier::class)->toSection($assessment->classroom_id, 'grades', 'grades', [
                'subject_ar' => $assessment->subject->name_ar, 'subject_en' => $assessment->subject->name_en ?: $assessment->subject->name_ar, 'title' => $assessment->title,
            ]);
        }

        return new AssessmentResource($assessment->load(self::WITH));
    }
}
