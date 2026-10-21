<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\TimetableGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json(['data' => [
            'periods' => Period::orderBy('day_of_week')->orderBy('number')->get()->map(fn (Period $p) => [
                'id' => $p->id, 'day_of_week' => $p->day_of_week, 'number' => $p->number, 'starts_at' => substr($p->starts_at, 0, 5), 'ends_at' => substr($p->ends_at, 0, 5),
            ])->values(),
            'entries' => $entries->map(fn (TimetableEntry $e) => [
                'id' => $e->id,
                'period_id' => $e->period_id,
                'subject' => $e->subject->only(['id', 'name_ar', 'name_en']),
                'teacher' => $e->teacher->only(['id', 'name']),
                'classroom' => $e->classroom->only(['id', 'name']),
                'room' => $e->room?->only(['id', 'name']),
                'is_locked' => $e->is_locked,
            ])->values(),
        ]]);
    }
}
