<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\TimetableChecker;
use App\Modules\Scheduling\Services\TimetableEditor;
use App\Shared\Export\XlsxExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Modules\Scheduling\Services\TimetableGenerator;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Generating and reading the timetable of the current academic year (admin). */
class TimetableController extends Controller
{
    /** Builds the timetable (preview with dry_run: nothing is saved); the same seed gives the same result. */
    public function generate(Request $request, TimetableGenerator $generator): JsonResponse
    {
        Gate::authorize('update', new Period);
        $data = $request->validate(['seed' => ['nullable', 'integer', 'between:0,2000000000'], 'dry_run' => ['sometimes', 'boolean']]);

        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            throw ValidationException::withMessages(['academic_year' => [__('scheduling.no_year')]]);
        }
        if (! Period::exists()) {
            throw ValidationException::withMessages(['periods' => [__('scheduling.no_periods')]]);
        }

        $planned = $generator->plan($year, (int) ($data['seed'] ?? random_int(1, 1_000_000)));
        $dryRun = $request->boolean('dry_run');
        if (! $dryRun) {
            $generator->apply($year, $planned, $request->user()->id);
        }

        return response()->json(['data' => ['dry_run' => $dryRun] + $planned['report']]);
    }

    /** Puts a lesson in a cell by hand (replacing what was there); the lesson is locked. */
    public function place(Request $request, TimetableEditor $editor): JsonResponse
    {
        Gate::authorize('update', new Period);
        $data = $request->validate([
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
        ]);
        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            throw ValidationException::withMessages(['academic_year' => [__('scheduling.no_year')]]);
        }

        $entry = $editor->place($year, Classroom::with('gradeLevel')->findOrFail($data['classroom_id']), Period::findOrFail($data['period_id']), (int) $data['subject_id'], $request->user()->id);

        return response()->json(['data' => $this->entry($entry->load(['subject', 'teacher', 'classroom', 'room']))]);
    }

    public function lock(Request $request, TimetableEntry $entry): JsonResponse
    {
        Gate::authorize('update', new Period);
        $data = $request->validate(['locked' => ['required', 'boolean']]);
        $entry->update(['is_locked' => $data['locked']]);

        return response()->json(['data' => $this->entry($entry->load(['subject', 'teacher', 'classroom', 'room']))]);
    }

    public function destroy(TimetableEntry $entry): Response
    {
        Gate::authorize('update', new Period);
        $entry->delete();

        return response()->noContent();
    }

    /** The periods a teacher cannot teach in. */
    public function unavailability(User $user): JsonResponse
    {
        Gate::authorize('update', new Period);
        abort_unless($user->hasRole(Role::Teacher), 404);

        return response()->json(['data' => ['period_ids' => $this->unavailablePeriods($user)]]);
    }

    /** Replaces the periods a teacher cannot teach in and reports the lessons already placed there. */
    public function saveUnavailability(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', new Period);
        abort_unless($user->hasRole(Role::Teacher), 404);
        $data = $request->validate(['period_ids' => ['present', 'array'], 'period_ids.*' => ['integer', 'distinct', 'exists:periods,id']]);

        DB::transaction(function () use ($user, $data): void {
            DB::table('teacher_unavailabilities')->where('teacher_id', $user->id)->delete();
            DB::table('teacher_unavailabilities')->insert(array_map(fn ($id) => ['teacher_id' => $user->id, 'period_id' => $id, 'created_at' => now(), 'updated_at' => now()], $data['period_ids']));
        });

        $clashes = TimetableEntry::where('teacher_id', $user->id)->whereIn('period_id', $data['period_ids'])->with(['classroom', 'subject'])->get()
            ->map(fn (TimetableEntry $e) => ['id' => $e->id, 'period_id' => $e->period_id, 'classroom' => $e->classroom->name, 'subject' => $e->subject->only(['id', 'name_ar', 'name_en'])])->values();

        return response()->json(['data' => ['period_ids' => $this->unavailablePeriods($user), 'clashes' => $clashes]]);
    }

    /** @return list<int> */
    private function unavailablePeriods(User $user): array
    {
        return DB::table('teacher_unavailabilities')->where('teacher_id', $user->id)->orderBy('period_id')->pluck('period_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    private function entry(TimetableEntry $e): array
    {
        return [
            'id' => $e->id,
            'period_id' => $e->period_id,
            'subject' => $e->subject->only(['id', 'name_ar', 'name_en']),
            'teacher' => $e->teacher->only(['id', 'name']),
            'classroom' => $e->classroom->only(['id', 'name']),
            'room' => $e->room?->only(['id', 'name']),
            'is_locked' => $e->is_locked,
        ];
    }

    /** The week of one section (classroom_id) or of one teacher (teacher_id). */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Period::class);
        $data = $request->validate([
            'classroom_id' => ['required_without:teacher_id', 'nullable', 'integer'],
            'teacher_id' => ['required_without:classroom_id', 'nullable', 'integer'],
        ]);

        $entries = TimetableEntry::query()->with(['subject', 'teacher', 'classroom', 'room'])
            ->when(isset($data['classroom_id']), fn ($q) => $q->where('classroom_id', $data['classroom_id']))
            ->when(isset($data['teacher_id']), fn ($q) => $q->where('teacher_id', $data['teacher_id']))
            ->get();

        // A section with a shorter day (grades 1-3) is shown without the periods it does not have.
        $limit = isset($data['classroom_id']) ? Classroom::with('gradeLevel')->find($data['classroom_id'])?->gradeLevel?->periods_per_day : null;
        $periods = Period::orderBy('day_of_week')->orderBy('number')->when($limit, fn ($q) => $q->where('number', '<=', $limit))->get();

        return response()->json(['data' => $this->grid($entries, $periods)]);
    }

    /** Every section's week in one go (admin): the sections, the bells and all the lessons, for the full timetable screen. */
    public function overview(): JsonResponse
    {
        Gate::authorize('viewAny', Period::class);
        $year = AcademicYear::where('is_current', true)->first();
        $classrooms = $year ? Classroom::where('academic_year_id', $year->id)->with(['gradeLevel', 'homeroomTeacher:id,name'])->get()->sortBy(fn (Classroom $c) => sprintf('%05d|%s', $c->gradeLevel?->sort_order ?? 0, $c->name))->values() : collect();
        $entries = $year ? TimetableEntry::where('academic_year_id', $year->id)->with(['subject:id,name_ar,name_en', 'teacher:id,name'])->get() : collect();

        return response()->json(['data' => [
            'periods' => Period::orderBy('day_of_week')->orderBy('number')->get()->map(fn (Period $p) => ['id' => $p->id, 'day_of_week' => $p->day_of_week, 'number' => $p->number, 'starts_at' => substr($p->starts_at, 0, 5), 'ends_at' => substr($p->ends_at, 0, 5)])->values(),
            'classrooms' => $classrooms->map(fn (Classroom $c) => ['id' => $c->id, 'name' => $c->name, 'grade_level_id' => $c->grade_level_id, 'grade_ar' => $c->gradeLevel?->name_ar, 'grade_en' => $c->gradeLevel?->name_en, 'periods_per_day' => $c->gradeLevel?->periods_per_day, 'homeroom' => $c->homeroomTeacher?->name])->values(),
            'entries' => $entries->map(fn (TimetableEntry $e) => ['id' => $e->id, 'classroom_id' => $e->classroom_id, 'period_id' => $e->period_id, 'subject' => $e->subject->only(['id', 'name_ar', 'name_en']), 'teacher' => $e->teacher->only(['id', 'name']), 'is_locked' => $e->is_locked])->values(),
        ]]);
    }

    /** What is wrong with the timetable (admin): clashes, missing or extra lessons, teachers outside their subjects... */
    public function check(TimetableChecker $checker): JsonResponse
    {
        Gate::authorize('viewAny', Period::class);

        return response()->json(['data' => $checker->check()]);
    }

    /** The full timetable as a sheet, like the paper one: a row per day and period, a column per section. */
    public function export(XlsxExport $xlsx): BinaryFileResponse
    {
        Gate::authorize('viewAny', Period::class);
        $year = AcademicYear::where('is_current', true)->first();
        $classrooms = $year ? Classroom::where('academic_year_id', $year->id)->with('gradeLevel')->get()->sortBy(fn (Classroom $c) => sprintf('%05d|%s', $c->gradeLevel?->sort_order ?? 0, $c->name))->values() : collect();
        $cell = TimetableEntry::where('academic_year_id', $year?->id)->with(['subject', 'teacher'])->get()->keyBy(fn ($e) => $e->classroom_id.'-'.$e->period_id);
        $ar = app()->getLocale() === 'ar';
        $rows = Period::orderBy('day_of_week')->orderBy('number')->get()->map(function (Period $p) use ($classrooms, $cell, $ar): array {
            return [__('scheduling.days.'.$p->day_of_week), $p->number, ...$classrooms->map(function (Classroom $c) use ($cell, $p, $ar): string {
                $e = $cell[$c->id.'-'.$p->id] ?? null;

                return $e === null ? '' : ($ar ? $e->subject->name_ar : $e->subject->name_en).' - '.$e->teacher->name;
            })->all()];
        });

        return $xlsx->download('timetable', __('scheduling.full_timetable'), [__('scheduling.day'), __('scheduling.period'), ...$classrooms->pluck('name')->all()], $rows);
    }

    /** The week of the signed-in teacher (their lessons) or student (their section's lessons). */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasRole(Role::Teacher)) {
            $entries = TimetableEntry::where('teacher_id', $user->id)->with(['subject', 'teacher', 'classroom', 'room'])->get();

            return response()->json(['data' => $this->grid($entries, Period::orderBy('day_of_week')->orderBy('number')->get()) + ['title' => $user->name]]);
        }
        abort_unless($user->hasRole(Role::Student) && $user->student !== null, 403);

        return $this->forStudent($user->student);
    }

    /** The week of the section a student is in (the student, their parents and admins). */
    public function forStudent(Student $student): JsonResponse
    {
        Gate::authorize('view-student-timetable', $student);

        $year = AcademicYear::where('is_current', true)->first();
        $classroom = $year ? $student->enrollments()->where('academic_year_id', $year->id)->with('classroom.gradeLevel')->first()?->classroom : null;
        if ($classroom === null) {
            return response()->json(['data' => ['title' => null, 'periods' => [], 'entries' => []]]);
        }

        $limit = $classroom->gradeLevel->periods_per_day;
        $periods = Period::orderBy('day_of_week')->orderBy('number')->when($limit, fn ($q) => $q->where('number', '<=', $limit))->get();
        $entries = TimetableEntry::where('classroom_id', $classroom->id)->with(['subject', 'teacher', 'classroom', 'room'])->get();

        return response()->json(['data' => $this->grid($entries, $periods) + ['title' => $classroom->name]]);
    }

    /**
     * @param  Collection<int, TimetableEntry>  $entries
     * @param  Collection<int, Period>  $periods
     * @return array<string, mixed>
     */
    private function grid(Collection $entries, Collection $periods): array
    {
        $ids = $periods->pluck('id')->flip();

        return [
            'periods' => $periods->map(fn (Period $p) => [
                'id' => $p->id, 'day_of_week' => $p->day_of_week, 'number' => $p->number, 'starts_at' => substr($p->starts_at, 0, 5), 'ends_at' => substr($p->ends_at, 0, 5),
            ])->values(),
            'entries' => $entries->filter(fn (TimetableEntry $e) => $ids->has($e->period_id))->map(fn (TimetableEntry $e) => $this->entry($e))->values(),
        ];
    }
}
