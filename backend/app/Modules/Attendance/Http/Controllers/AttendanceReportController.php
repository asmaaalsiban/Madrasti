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
use App\Shared\Export\XlsxExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Absence reports: per student for a period, and the dates behind one student's numbers. */
class AttendanceReportController extends Controller
{
    /** How many days each student was absent / late (only the students who were at least once). Admin: any classroom. Teacher: only their own. */
    public function index(Request $request): JsonResponse
    {
        [$query, $from, $to] = $this->report($request);
        $rows = $query->paginate((int) ($request->query('per_page') ?: 15));

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

    /** The same report as an Excel file (every row, not one page). */
    public function export(Request $request, XlsxExport $xlsx): BinaryFileResponse
    {
        [$query, $from, $to] = $this->report($request);
        $rows = $query->get()->map(fn ($r) => [$r->student_number, $r->name_ar, $r->name_en, $r->classroom, (int) $r->absent_count, (int) $r->late_count]);

        return $xlsx->download(__('reports.attendance_file').'_'.$from.'_'.$to, __('reports.attendance_sheet'), [
            __('reports.student_number'), __('reports.name_ar'), __('reports.name_en'), __('reports.classroom'), __('reports.absent_days'), __('reports.late_days'),
        ], $rows);
    }

    /** @return array{0: \Illuminate\Database\Eloquent\Builder<Enrollment>, 1: string, 2: string} the report query and its period */
    private function report(Request $request): array
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

        $query = Enrollment::query()
            ->when($year, fn ($q) => $q->where('enrollments.academic_year_id', $year->id))
            ->when(isset($data['classroom_id']), fn ($q) => $q->where('enrollments.classroom_id', $data['classroom_id']))
            ->when(! $user->hasRole(Role::Admin), fn ($q) => $q->whereIn('enrollments.classroom_id', Classroom::where('homeroom_teacher_id', $user->id)->select('id')))
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->whereNull('students.deleted_at')
            // Only students who were absent or late at least once in the period.
            ->whereIn('students.id', StudentAttendance::query()->select('student_id')->whereIn('status', [AttendanceStatus::Absent->value, AttendanceStatus::Late->value])->whereBetween('date', [$from, $to]))
            ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
            ->select('students.id', 'students.student_number', 'students.name_ar', 'students.name_en', 'classrooms.name as classroom')
            ->selectSub($count(AttendanceStatus::Absent), 'absent_count')
            ->selectSub($count(AttendanceStatus::Late), 'late_count')
            ->orderByRaw('absent_count desc')->orderByRaw('late_count desc')->orderBy('students.name_ar')
            ;

        return [$query, $from, $to];
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
