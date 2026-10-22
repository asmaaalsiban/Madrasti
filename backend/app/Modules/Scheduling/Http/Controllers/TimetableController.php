<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\TimetableGenerator;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'entries' => $entries->filter(fn (TimetableEntry $e) => $ids->has($e->period_id))->map(fn (TimetableEntry $e) => [
                'id' => $e->id,
                'period_id' => $e->period_id,
                'subject' => $e->subject->only(['id', 'name_ar', 'name_en']),
                'teacher' => $e->teacher->only(['id', 'name']),
                'classroom' => $e->classroom->only(['id', 'name']),
                'room' => $e->room?->only(['id', 'name']),
                'is_locked' => $e->is_locked,
            ])->values(),
        ];
    }
}
