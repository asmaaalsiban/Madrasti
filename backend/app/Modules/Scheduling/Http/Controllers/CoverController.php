<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Scheduling\Models\LessonCover;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\CoverPlanner;
use App\Shared\Enums\StaffType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Covering the lessons of an absent teacher (admin): who is away, their lessons, who can cover each one, and the choice. */
class CoverController extends Controller
{
    public function __construct(private readonly CoverPlanner $planner) {}

    /** Teachers marked absent or on leave on a date, and every teacher (to pick one by hand). */
    public function teachers(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $away = DB::table('staff_attendances')->where('date', $date)->whereIn('status', ['absent', 'leave'])->pluck('status', 'user_id');
        $rows = User::where('is_active', true)->whereHas('staffProfile', fn ($q) => $q->whereIn('staff_type', StaffType::valuesIn('teaching')))->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'away' => $away[$u->id] ?? null])->sortByDesc(fn ($r) => $r['away'] !== null)->values();

        return response()->json(['data' => $rows]);
    }

    public function plan(Request $request): JsonResponse
    {
        $d = $request->validate(['teacher_id' => ['required', 'integer', 'exists:users,id'], 'date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->planner->plan(User::findOrFail($d['teacher_id']), $d['date'])]);
    }

    public function store(Request $request, Notifier $notifier): JsonResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'period_id' => ['required', 'integer', 'exists:periods,id'],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'], 'cover_teacher_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $year = AcademicYear::where('is_current', true)->firstOrFail();
        $period = Period::findOrFail($d['period_id']);
        $entry = TimetableEntry::where('academic_year_id', $year->id)->where('classroom_id', $d['classroom_id'])->where('period_id', $period->id)->first();
        if ($entry === null || Carbon::parse($d['date'])->dayOfWeek !== $period->day_of_week) {
            throw ValidationException::withMessages(['period_id' => [__('scheduling.cover_no_lesson')]]);
        }
        if (! $this->planner->canCover((int) $d['cover_teacher_id'], $period->id, $d['date'], $entry->teacher_id)) {
            throw ValidationException::withMessages(['cover_teacher_id' => [__('scheduling.cover_teacher_busy')]]);
        }
        $cover = LessonCover::updateOrCreate(
            ['date' => $d['date'], 'period_id' => $period->id, 'classroom_id' => $entry->classroom_id],
            ['subject_id' => $entry->subject_id, 'absent_teacher_id' => $entry->teacher_id, 'cover_teacher_id' => $d['cover_teacher_id'], 'created_by' => $request->user()->id],
        );
        $room = DB::table('classrooms')->where('id', $entry->classroom_id)->value('name');
        $subject = DB::table('subjects')->where('id', $entry->subject_id)->first();
        $notifier->toUsers([$cover->cover_teacher_id], 'lesson_cover', ['classroom' => $room, 'period' => $period->number, 'date' => $d['date'], 'subject_ar' => $subject->name_ar, 'subject_en' => $subject->name_en, 'absent' => (string) DB::table('users')->where('id', $entry->teacher_id)->value('name')], '/teacher/timetable');

        return response()->json(['data' => ['id' => $cover->id]], 201);
    }

    public function destroy(LessonCover $cover): Response
    {
        $cover->delete();

        return response()->noContent();
    }
}
