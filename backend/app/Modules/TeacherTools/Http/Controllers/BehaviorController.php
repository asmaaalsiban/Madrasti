<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Term;
use App\Modules\TeacherTools\Models\BehaviorCriterion;
use App\Modules\TeacherTools\Models\BehaviorEvaluation;
use App\Modules\TeacherTools\Models\BehaviorNote;
use App\Modules\TeacherTools\Models\BehaviorStudentCriterion;
use App\Modules\TeacherTools\Services\BehaviorAccess;
use App\Modules\TeacherTools\Services\BehaviorSummary;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Behaviour evaluation. A teacher scores the students they teach once a week against the school's criteria and adds a note;
 * months and terms are built from the weeks. Quick positive/negative notes record single events.
 */
class BehaviorController extends Controller
{
    public function __construct(private readonly BehaviorAccess $access) {}

    /** The sections the signed-in teacher (admin: all) may evaluate. */
    public function classrooms(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->access->classrooms($request->user())->map->only(['id', 'name'])->values()]);
    }

    /** The students of one section with this teacher's evaluation of the week that contains `date` (default today). */
    public function week(Request $request): JsonResponse
    {
        $data = $request->validate(['classroom_id' => ['required', 'integer'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $user = $request->user();
        abort_unless($this->access->classrooms($user)->contains('id', (int) $data['classroom_id']), 403);

        $week = $this->weekStart($data['date'] ?? now()->toDateString());
        $year = AcademicYear::where('is_current', true)->first();
        $students = Student::whereIn('id', Enrollment::where('classroom_id', $data['classroom_id'])->where('academic_year_id', $year?->id)->select('student_id'))->orderBy('name_ar')->get();
        $evaluations = BehaviorEvaluation::with(['scores', 'specialScores'])->where('teacher_id', $user->id)->where('week_starts_on', $week)->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');

        $special = BehaviorStudentCriterion::where('is_active', true)->whereIn('student_id', $students->pluck('id'))->groupBy('student_id')->selectRaw('student_id, count(*) as n')->pluck('n', 'student_id');

        return response()->json(['data' => [
            'week_starts_on' => $week,
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en, 'special_count' => (int) ($special[$s->id] ?? 0),
                'evaluation' => ($e = $evaluations->get($s->id)) ? $this->evaluation($e) : null,
            ])->values(),
        ]]);
    }

    /** Saves (or replaces) the teacher's evaluation of a student for the week of `evaluated_on`. */
    public function save(Request $request): JsonResponse
    {
        $criteria = BehaviorCriterion::where('is_active', true)->pluck('id')->all();
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'evaluated_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'scores' => ['present', 'array'],
            'scores.*' => ['nullable', 'integer', 'between:1,5'],
            'special_scores' => ['sometimes', 'nullable', 'array'],
            'special_scores.*' => ['nullable', 'integer', 'between:1,5'],
            'note' => ['nullable', 'string', 'max:3000'],
            'visible_to_parent' => ['sometimes', 'boolean'],
        ]);
        $scores = collect($data['scores'])->filter(fn ($v) => $v !== null);
        if ($scores->keys()->diff($criteria)->isNotEmpty()) {
            throw ValidationException::withMessages(['scores' => [__('teacher_tools.unknown_criterion')]]);
        }
        $special = collect($data['special_scores'] ?? [])->filter(fn ($v) => $v !== null);
        if ($special->isNotEmpty()) {
            // Only this student's own active special criteria.
            $own = BehaviorStudentCriterion::where('student_id', $data['student_id'])->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id);
            if ($special->keys()->map(fn ($k) => (string) $k)->diff($own)->isNotEmpty()) {
                throw ValidationException::withMessages(['special_scores' => [__('teacher_tools.unknown_criterion')]]);
            }
        }
        if ($scores->isEmpty() && $special->isEmpty() && blank($data['note'] ?? null)) {
            throw ValidationException::withMessages(['scores' => [__('teacher_tools.empty_evaluation')]]);
        }
        $student = Student::findOrFail($data['student_id']);
        abort_unless($this->access->canEvaluate($request->user(), $student), 403);

        $on = $data['evaluated_on'] ?? now()->toDateString();
        $evaluation = DB::transaction(function () use ($request, $data, $scores, $special, $on): BehaviorEvaluation {
            $evaluation = BehaviorEvaluation::updateOrCreate(
                ['student_id' => $data['student_id'], 'teacher_id' => $request->user()->id, 'week_starts_on' => $this->weekStart($on)],
                ['evaluated_on' => $on, 'note' => $data['note'] ?? null, 'visible_to_parent' => $data['visible_to_parent'] ?? true],
            );
            $evaluation->scores()->delete();
            foreach ($scores as $criterion => $score) {
                $evaluation->scores()->create(['behavior_criterion_id' => $criterion, 'score' => $score]);
            }

            $evaluation->specialScores()->delete();
            foreach ($special as $criterion => $score) {
                $evaluation->specialScores()->create(['behavior_student_criterion_id' => $criterion, 'score' => $score]);
            }

            return $evaluation;
        });

        return response()->json(['data' => $this->evaluation($evaluation->load(['scores', 'specialScores']))]);
    }

    /** A student's behaviour: weekly, monthly and term results (each built from the one below) and the notes. */
    public function student(Request $request, Student $student, BehaviorSummary $summary): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view-student-behavior', $student);
        $staff = $user->hasRole(Role::Admin, Role::Teacher);

        $evaluations = BehaviorEvaluation::with(['scores', 'specialScores', 'teacher'])->where('student_id', $student->id)->orderBy('week_starts_on')->get();
        $year = AcademicYear::where('is_current', true)->first();
        $terms = Term::where('academic_year_id', $year?->id)->orderBy('starts_on')->get();
        $notes = BehaviorNote::with('teacher')->where('student_id', $student->id)->when(! $staff, fn ($q) => $q->where('visible_to_parent', true))->orderByDesc('noted_on')->orderByDesc('id')->get();

        // The special evaluation: each of this student's own criteria with its average and weekly scores (parents see only evaluations shared with them).
        $visible = $evaluations->filter(fn ($e) => $staff || $e->visible_to_parent);
        $special = BehaviorStudentCriterion::where('student_id', $student->id)->orderBy('sort_order')->orderBy('id')->get()->map(function (BehaviorStudentCriterion $c) use ($visible): array {
            $history = $visible->flatMap(fn ($e) => $e->specialScores->where('behavior_student_criterion_id', $c->id)->map(fn ($s) => ['week_starts_on' => $e->week_starts_on->format('Y-m-d'), 'score' => $s->score, 'teacher' => $e->teacher->name]))->sortBy('week_starts_on')->values();

            return ['id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active, 'average' => $history->isEmpty() ? null : round($history->avg('score'), 1), 'history' => $history->all()];
        })->filter(fn ($c) => $c['is_active'] || $c['history'] !== [])->values();

        return response()->json(['data' => $summary->build($evaluations, $terms) + [
            'special' => $special,
            'criteria' => BehaviorCriterion::orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en', 'is_active']),
            'remarks' => $evaluations->filter(fn ($e) => filled($e->note) && ($staff || $e->visible_to_parent))->sortByDesc('evaluated_on')->map(fn ($e) => [
                'id' => $e->id, 'evaluated_on' => $e->evaluated_on->format('Y-m-d'), 'week_starts_on' => $e->week_starts_on->format('Y-m-d'), 'teacher' => $e->teacher->name, 'note' => $e->note,
            ])->values(),
            'notes' => $notes->map(fn (BehaviorNote $n) => [
                'id' => $n->id, 'type' => $n->type, 'note' => $n->note, 'noted_on' => $n->noted_on->format('Y-m-d'), 'teacher' => $n->teacher->name, 'visible_to_parent' => $n->visible_to_parent,
            ])->values(),
        ]]);
    }

    public function addNote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'type' => ['required', 'in:positive,negative'],
            'note' => ['required', 'string', 'max:3000'],
            'noted_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'visible_to_parent' => ['sometimes', 'boolean'],
        ]);
        abort_unless($this->access->canEvaluate($request->user(), Student::findOrFail($data['student_id'])), 403);

        $note = BehaviorNote::create($data + ['teacher_id' => $request->user()->id, 'noted_on' => $data['noted_on'] ?? now()->toDateString()]);

        return response()->json(['data' => ['id' => $note->id, 'type' => $note->type, 'note' => $note->note, 'noted_on' => $note->noted_on->format('Y-m-d')]], 201);
    }

    public function deleteNote(Request $request, BehaviorNote $note): Response
    {
        abort_unless($request->user()->hasRole(Role::Admin) || $note->teacher_id === $request->user()->id, 403);
        $note->delete();

        return response()->noContent();
    }

    /** The Sunday of the week of a date (the school week starts on Sunday). */
    private function weekStart(string $date): string
    {
        return Carbon::parse($date)->startOfWeek(Carbon::SUNDAY)->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function evaluation(BehaviorEvaluation $e): array
    {
        return [
            'id' => $e->id, 'evaluated_on' => $e->evaluated_on->format('Y-m-d'), 'week_starts_on' => $e->week_starts_on->format('Y-m-d'),
            'note' => $e->note, 'visible_to_parent' => $e->visible_to_parent,
            'scores' => $e->scores->pluck('score', 'behavior_criterion_id'),
            'special_scores' => $e->specialScores->pluck('score', 'behavior_student_criterion_id'),
        ];
    }
}
