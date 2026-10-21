<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\StaffType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The page of a head of department: the teachers of the same subject, the classrooms each teaches, their
 * homeroom classroom and how up to date its attendance is.
 */
class DepartmentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->staffProfile()->with('subject')->first();
        abort_unless($profile?->staff_type === StaffType::HeadOfDepartment, 403, __('auth.forbidden'));

        $subject = $profile->subject;
        $teachers = $subject === null ? collect() : User::query()
            ->where('is_active', true)->whereKeyNot($user->id)
            ->whereHas('staffProfile', fn ($q) => $q->where('subject_id', $subject->id)->whereIn('staff_type', StaffType::valuesIn('teaching')))
            ->with('staffProfile')->orderBy('name')->get();

        $currentRooms = Classroom::whereHas('academicYear', fn ($q) => $q->where('is_current', true))->get()->keyBy('id');
        $taught = $subject === null ? collect() : DB::table('classroom_subject_teacher')
            ->where('subject_id', $subject->id)->whereIn('teacher_id', $teachers->pluck('id'))->whereIn('classroom_id', $currentRooms->keys())
            ->get()->groupBy('teacher_id');

        $homerooms = $currentRooms->whereIn('homeroom_teacher_id', $teachers->pluck('id'))->keyBy('homeroom_teacher_id');
        $today = now()->toDateString();
        $recorded = StudentAttendance::query()
            ->whereIn('classroom_id', $homerooms->pluck('id'))->whereBetween('date', [now()->subDays(29)->toDateString(), $today])
            ->selectRaw('classroom_id, count(distinct date) as days, max(date) as last_day')->groupBy('classroom_id')->get()->keyBy('classroom_id');

        return response()->json(['data' => [
            'subject' => $subject?->only(['id', 'name_ar', 'name_en']),
            'teachers' => $teachers->map(function (User $teacher) use ($homerooms, $taught, $currentRooms, $recorded, $today): array {
                $room = $homerooms->get($teacher->id);
                $stats = $room ? $recorded->get($room->id) : null;

                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'phone' => $teacher->phone,
                    'staff_type' => $teacher->staffProfile->staff_type->value,
                    'homeroom' => $room?->name,
                    'classrooms' => collect($taught->get($teacher->id, []))->map(fn ($row) => $currentRooms->get($row->classroom_id)?->name)->filter()->sort()->values(),
                    'attendance' => $room ? [
                        'recorded_today' => $stats !== null && $stats->last_day === $today,
                        'days_last_30' => (int) ($stats->days ?? 0),
                    ] : null,
                ];
            })->values(),
        ]]);
    }
}
