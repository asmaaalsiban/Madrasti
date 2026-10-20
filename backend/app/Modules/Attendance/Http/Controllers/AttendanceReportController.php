<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\AttendanceStatus;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Absence reports: per student for a period, and the dates behind one student's numbers. */
class AttendanceReportController extends Controller
{
    /** How many days each student was absent / late. Admin: any classroom. Teacher: only their own. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $year = isset($data['academic_year_id']) ? AcademicYear::find($data['academic_year_id']) : AcademicYear::where('is_current', true)->first();
        $from = $data['from'] ?? $year?->starts_on->format('Y-m-d') ?? '1970-01-01';
        $to = $data['to'] ?? $year?->ends_on->format('Y-m-d') ?? '9999-12-31';

        if (isset($data['classroom_id'])) {
            Gate::authorize('take-attendance', Classroom::findOrFail($data['classroom_id']));
        }

        $count = fn (AttendanceStatus $status) => StudentAttendance::query()->selectRaw('count(*)')
            ->whereColumn('student_attendances.student_id', 'students.id')
            ->where('status', $status->value)->whereBetween('date', [$from, $to]);

        $rows = Enrollment::query()
            ->when($year, fn ($q) => $q->where('enrollments.academic_year_id', $year->id))
            ->when(isset($data['classroom_id']), fn ($q) => $q->where('enrollments.classroom_id', $data['classroom_id']))
            ->when(! $user->hasRole(Role::Admin), fn ($q) => $q->whereIn('enrollments.classroom_id', Classroom::where('homeroom_teacher_id', $user->id)->select('id')))
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->whereNull('students.deleted_at')
            ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
            ->select('students.id', 'students.student_number', 'students.name_ar', 'students.name_en', 'classrooms.name as classroom')
            ->selectSub($count(AttendanceStatus::Absent), 'absent_count')
            ->selectSub($count(AttendanceStatus::Late), 'late_count')
            ->orderByRaw('absent_count desc')->orderByRaw('late_count desc')->orderBy('students.name_ar')
            ->paginate((int) ($data['per_page'] ?? 15));

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($row) => [
                'student' => $row->only(['id', 'student_number', 'name_ar', 'name_en']),
                'classroom' => $row->classroom,
                'absent' => (int) $row->absent_count,
                'late' => (int) $row->late_count,
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'from' => $from, 'to' => $to],
        ]);
    }

    /** One student's days (newest first) and totals. ?status=absent|late limits the list, not the totals. */
    public function student(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('view-student-attendance', $student);
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:'.implode(',', AttendanceStatus::values())],
        ]);

        $year = AcademicYear::where('is_current', true)->first();
        $from = $data['from'] ?? $year?->starts_on->format('Y-m-d') ?? '1970-01-01';
        $to = $data['to'] ?? $year?->ends_on->format('Y-m-d') ?? '9999-12-31';

        $days = StudentAttendance::where('student_id', $student->id)->whereBetween('date', [$from, $to]);
        $summary = (clone $days)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $records = $days->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('date')->get(['date', 'status', 'note']);

        return response()->json(['data' => [
            'student' => $student->only(['id', 'student_number', 'name_ar', 'name_en']),
            'from' => $from,
            'to' => $to,
            'summary' => [
                'present' => (int) ($summary['present'] ?? 0),
                'late' => (int) ($summary['late'] ?? 0),
                'absent' => (int) ($summary['absent'] ?? 0),
            ],
            'records' => $records->map(fn ($r) => ['date' => $r->date->format('Y-m-d'), 'status' => $r->status->value, 'note' => $r->note])->values(),
        ]]);
    }
}
