<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Term;
use App\Modules\Scheduling\Models\ExamSession;
use App\Modules\Scheduling\Services\ExamScheduler;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** The exam timetable: the principal generates and edits it; teachers, students and parents read their part. */
class ExamController extends Controller
{
    public function __construct(private readonly ExamScheduler $scheduler) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['term_id' => ['nullable', 'integer'], 'grade_level_id' => ['nullable', 'integer']]);
        $termId = $data['term_id'] ?? $this->defaultTerm()?->id;
        $rows = ExamSession::with($this->with())->when($termId, fn ($q) => $q->where('term_id', $termId), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($data['grade_level_id'] ?? null, fn ($q, $g) => $q->whereHas('classroom', fn ($c) => $c->where('grade_level_id', $g)))
            ->orderBy('exam_date')->orderBy('starts_at')->get()->sortBy(fn ($s) => [$s->exam_date->toDateString(), $s->starts_at, $s->classroom->name])->values();

        return response()->json(['term_id' => $termId, 'data' => $rows->map(fn ($s) => $this->present($s))->values()]);
    }

    /** Builds the timetable (or only previews it with `dry_run`). */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'term_id' => ['required', 'integer', 'exists:terms,id'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'exclude_dates' => ['nullable', 'array'], 'exclude_dates.*' => ['date_format:Y-m-d'], 'first_period' => ['required', 'integer', 'between:1,12'], 'periods_per_exam' => ['required', 'integer', 'between:1,4'],
            'slots_per_day' => ['required', 'integer', 'between:1,4'], 'grade_per_day' => ['required', 'integer', 'between:1,4', 'lte:slots_per_day'], 'fill_missing' => ['sometimes', 'boolean'], 'grade_level_ids' => ['nullable', 'array'], 'grade_level_ids.*' => ['integer'], 'dry_run' => ['sometimes', 'boolean'],
        ]);
        $term = Term::findOrFail($data['term_id']);
        $plan = $this->scheduler->plan($term, $data);
        $dry = $request->boolean('dry_run');
        if (! $dry) {
            $this->scheduler->apply($term, $plan);
        }

        return response()->json(['data' => ['dry_run' => $dry, 'sessions' => count($plan['rows']), 'days' => $plan['days'], 'grades' => $plan['grades'], 'warnings' => $plan['warnings']]]);
    }

    /** Moves an exam by hand and/or changes who watches it; clashes are refused. */
    public function update(Request $request, ExamSession $session): JsonResponse
    {
        $data = $request->validate(['exam_date' => ['sometimes', 'date_format:Y-m-d'], 'period_from' => ['sometimes', 'integer', 'between:1,12'], 'period_to' => ['sometimes', 'integer', 'between:1,12', 'gte:period_from'], 'starts_at' => ['sometimes', 'date_format:H:i'], 'ends_at' => ['sometimes', 'date_format:H:i'], 'invigilator_ids' => ['sometimes', 'array', 'max:6'], 'invigilator_ids.*' => ['integer', 'exists:users,id']]);
        $date = $data['exam_date'] ?? $session->exam_date->toDateString();
        $start = isset($data['starts_at']) ? $data['starts_at'].':00' : $session->starts_at;
        $end = isset($data['ends_at']) ? $data['ends_at'].':00' : $session->ends_at;
        $periods = ['period_from' => $session->period_from, 'period_to' => $session->period_to];
        if (isset($data['period_from'])) {
            // Moved to other periods: the times are the school's, and the teachers who have the section then watch it.
            $times = $this->scheduler->timesFor($date, $data['period_from'], $data['period_to'] ?? $data['period_from']);
            if ($times === null) {
                throw ValidationException::withMessages(['period_from' => [__('exams.no_such_period')]]);
            }
            [$start, $end] = [$times['start'], $times['end']];
            $periods = ['period_from' => $data['period_from'], 'period_to' => $data['period_to'] ?? $data['period_from']];
            $data['invigilator_ids'] ??= $this->scheduler->watchersFor($session->classroom, $date, $periods['period_from'], $periods['period_to']);
        } elseif (isset($data['starts_at']) || isset($data['ends_at'])) {
            $periods = ['period_from' => null, 'period_to' => null]; // set by hand: no longer tied to periods
        }
        if ($end <= $start) {
            throw ValidationException::withMessages(['ends_at' => [__('exams.time_order')]]);
        }
        if ($this->scheduler->clash($session, $date, $start, $end) !== null) {
            throw ValidationException::withMessages(['exam_date' => [__('exams.class_clash')]]);
        }
        $busy = $this->scheduler->busyInvigilators($session, $date, $start, $end);
        $teachers = $data['invigilator_ids'] ?? $session->invigilators()->pluck('users.id')->all();
        if ($busy->intersect($teachers)->isNotEmpty()) {
            throw ValidationException::withMessages(['invigilator_ids' => [__('exams.teacher_clash')]]);
        }
        $session->update(['exam_date' => $date, 'starts_at' => $start, 'ends_at' => $end] + $periods);
        if (isset($data['invigilator_ids'])) {
            $session->invigilators()->sync($data['invigilator_ids']);
        }

        return response()->json(['data' => $this->present($session->refresh()->load($this->with()))]);
    }

    public function destroy(ExamSession $session): Response
    {
        $session->delete();

        return response()->noContent();
    }

    /** My exams: a teacher's rooms to watch and the exams of the sections they teach; a student's own section. */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasRole(Role::Student)) {
            abort_unless($user->student !== null, 403);

            return $this->forStudent($user->student);
        }
        abort_unless($user->hasRole(Role::Teacher) || $user->hasRole(Role::Admin), 403);
        $watching = ExamSession::with($this->with())->whereHas('invigilators', fn ($q) => $q->whereKey($user->id))->get();
        $teaching = ExamSession::with($this->with())->whereIn('classroom_id', DB::table('classroom_subject_teacher')->where('teacher_id', $user->id)->select('classroom_id'))
            ->whereIn('subject_id', DB::table('classroom_subject_teacher')->where('teacher_id', $user->id)->select('subject_id'))->get();
        $sort = fn ($c) => $c->sortBy(fn ($s) => [$s->exam_date->toDateString(), $s->starts_at])->map(fn ($s) => $this->present($s))->values();

        return response()->json(['data' => ['watching' => $sort($watching), 'teaching' => $sort($teaching)]]);
    }

    /** The exams of the section a student is in (the student, their parents and admins). */
    public function forStudent(Student $student): JsonResponse
    {
        Gate::authorize('view-student-timetable', $student);
        $year = AcademicYear::where('is_current', true)->first();
        $classroomId = $year ? $student->enrollments()->where('academic_year_id', $year->id)->value('classroom_id') : null;
        $rows = $classroomId === null ? collect() : ExamSession::with($this->with())->where('classroom_id', $classroomId)->get()->sortBy(fn ($s) => [$s->exam_date->toDateString(), $s->starts_at])->values();

        return response()->json(['data' => $rows->map(fn ($s) => $this->present($s))->values()]);
    }

    /** @return list<string> */
    private function with(): array
    {
        return ['classroom.gradeLevel', 'subject', 'term', 'invigilators:users.id,users.name'];
    }

    /** The running term, else the next one. */
    private function defaultTerm(): ?Term
    {
        return Term::where('starts_on', '<=', now())->where('ends_on', '>=', now())->first() ?? Term::whereDate('starts_on', '>=', now())->orderBy('starts_on')->first() ?? Term::orderByDesc('starts_on')->first();
    }

    /** @return array<string, mixed> */
    private function present(ExamSession $s): array
    {
        return [
            'id' => $s->id, 'term' => ['id' => $s->term->id, 'name' => $s->term->name], 'date' => $s->exam_date->toDateString(), 'period_from' => $s->period_from, 'period_to' => $s->period_to, 'starts_at' => substr($s->starts_at, 0, 5), 'ends_at' => substr($s->ends_at, 0, 5),
            'classroom' => ['id' => $s->classroom->id, 'name' => $s->classroom->name, 'grade' => ['id' => $s->classroom->gradeLevel->id, 'name_ar' => $s->classroom->gradeLevel->name_ar, 'name_en' => $s->classroom->gradeLevel->name_en]],
            'subject' => ['id' => $s->subject->id, 'name_ar' => $s->subject->name_ar, 'name_en' => $s->subject->name_en],
            'invigilators' => $s->invigilators->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ];
    }
}
