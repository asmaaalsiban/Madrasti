<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Attendance\Actions\SaveAttendance;
use App\Modules\Attendance\Http\Requests\SaveAttendanceRequest;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Daily attendance of a classroom, taken by its homeroom teacher (or an admin). */
class AttendanceController extends Controller
{
    /** The classrooms of the current academic year the signed-in user may take attendance for. */
    public function classrooms(Request $request): JsonResponse
    {
        $user = $request->user();
        $rooms = Classroom::query()
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->when(! $user->hasRole(Role::Admin), fn ($q) => $q->where('homeroom_teacher_id', $user->id))
            ->with('gradeLevel')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rooms->map(fn (Classroom $room) => [
            'id' => $room->id,
            'name' => $room->name,
            'grade_level' => $room->gradeLevel?->only(['id', 'name_ar', 'name_en']),
        ])->values()]);
    }

    /** The students of a classroom with what was recorded for the day (null = not recorded yet). */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $classroom = Classroom::findOrFail($data['classroom_id']);
        Gate::authorize('take-attendance', $classroom);

        $students = Enrollment::query()
            ->where('classroom_id', $classroom->id)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->whereNull('students.deleted_at')
            ->orderBy('students.name_ar')
            ->get(['students.id', 'students.student_number', 'students.name_ar', 'students.name_en']);

        $records = StudentAttendance::whereIn('student_id', $students->pluck('id'))->where('date', $data['date'])->get()->keyBy('student_id');

        return response()->json(['data' => [
            'classroom' => ['id' => $classroom->id, 'name' => $classroom->name],
            'date' => $data['date'],
            'recorded' => $records->isNotEmpty(),
            'students' => $students->map(fn ($student) => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'name_ar' => $student->name_ar,
                'name_en' => $student->name_en,
                'status' => $records->get($student->id)?->status->value,
                'note' => $records->get($student->id)?->note,
            ])->values(),
        ]]);
    }

    public function update(SaveAttendanceRequest $request, SaveAttendance $save): JsonResponse
    {
        $classroom = Classroom::findOrFail($request->integer('classroom_id'));
        Gate::authorize('take-attendance', $classroom);

        /** @var User $user */
        $user = $request->user();
        $save->handle($classroom, $request->string('date')->toString(), $request->validated('records'), $user);

        return response()->json(['message' => 'ok']);
    }
}
