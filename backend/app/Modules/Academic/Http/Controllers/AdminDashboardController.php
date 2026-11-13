<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Auth\Models\DataRequest;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Grading\Models\AssessmentScore;
use App\Modules\HR\Models\StaffAttendance;
use App\Modules\Procurement\Models\ProcurementRequest;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Everything the principal's home page draws, in ONE answer: today's attendance, the last two weeks,
 * money billed / collected / late, students per level, average marks per subject, and what waits for a decision.
 * Amounts are sent as numbers in the school's currency; the page formats them.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = now()->toDateString();

        return response()->json(['data' => [
            'people' => [
                'students' => Student::count(),
                'teachers' => User::where('role', Role::Teacher)->where('is_active', true)->count(),
            ],
            'students_today' => $this->counts(StudentAttendance::whereDate('date', $today)->pluck('status')),
            'staff_today' => $this->counts(StaffAttendance::whereDate('date', $today)->pluck('status')),
            'attendance_trend' => $this->trend(),
            'finance' => $this->finance(),
            'students_by_level' => $this->byLevel(),
            'marks_by_subject' => $this->marks(),
            'waiting' => [
                'procurement' => ProcurementRequest::where('status', 'pending')->count(),
                'privacy' => DataRequest::where('status', 'pending')->count(),
            ],
            'frequent_absences' => $this->frequentAbsences(),
        ]]);
    }

    /** @param \Illuminate\Support\Collection<int, mixed> $statuses */
    private function counts($statuses): array
    {
        $by = $statuses->map(fn ($s) => is_object($s) ? $s->value : (string) $s)->countBy();

        return ['present' => (int) $by->get('present', 0), 'late' => (int) $by->get('late', 0), 'absent' => (int) $by->get('absent', 0), 'excused' => (int) ($by->get('excused', 0) + $by->get('leave', 0))];
    }

    /** Share of students who came (present or late) on each of the last 14 days that have records. */
    private function trend(): array
    {
        $from = now()->subDays(13)->toDateString();

        return StudentAttendance::whereDate('date', '>=', $from)->get(['date', 'status'])
            ->groupBy(fn ($r) => $r->date->toDateString())->sortKeys()
            ->map(function ($rows, $date) {
                $came = $rows->filter(fn ($r) => in_array($r->status->value, ['present', 'late'], true))->count();

                return ['date' => $date, 'rate' => round($came / max(1, $rows->count()) * 100, 1), 'total' => $rows->count()];
            })->values()->all();
    }

    /** Added up by the database: a school's fees, payments and installments run to thousands of rows, so they are never loaded into PHP. */
    private function finance(): array
    {
        $billed = (float) StudentFee::query()->selectRaw('coalesce(sum(total_amount - discount), 0) as total')->value('total');
        $overdue = (float) Installment::whereDate('due_date', '<', now()->toDateString())->where('amount', '>', DB::raw('paid_amount'))
            ->selectRaw('coalesce(sum(amount - paid_amount), 0) as total')->value('total');
        // One row per day with payments (not per payment): the months are added up from those.
        $perDay = Payment::where('method', '!=', Payment::CARRY)->groupBy('paid_on')->selectRaw('paid_on, sum(amount) as total')->get();
        $months = collect(range(5, 0))->map(fn ($n) => now()->startOfMonth()->subMonths($n)->format('Y-m'));

        return [
            'currency' => (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD'),
            'billed' => round($billed, 2),
            'collected' => round((float) $perDay->sum('total'), 2),
            'overdue' => round($overdue, 2),
            'monthly' => $months->map(fn ($m) => ['month' => $m, 'amount' => round((float) $perDay->filter(fn ($p) => $p->paid_on->format('Y-m') === $m)->sum('total'), 2)])->all(),
        ];
    }

    private function byLevel(): array
    {
        $year = AcademicYear::where('is_current', true)->first();
        $classrooms = Classroom::pluck('grade_level_id', 'id');
        $perLevel = Enrollment::when($year, fn ($q) => $q->where('academic_year_id', $year->id))->get(['classroom_id'])
            ->groupBy(fn ($e) => $classrooms[$e->classroom_id] ?? 0)->map->count();

        return GradeLevel::orderBy('sort_order')->get()->map(fn ($l) => ['id' => $l->id, 'name_ar' => $l->name_ar, 'name_en' => $l->name_en, 'count' => (int) $perLevel->get($l->id, 0)])->all();
    }

    /** Average mark, as a percent of the maximum, for each subject that has published marks (averaged by the database, not row by row in PHP). */
    private function marks(): array
    {
        $subjects = Subject::pluck('name_ar', 'id');
        $english = Subject::pluck('name_en', 'id');

        return AssessmentScore::query()->whereNotNull('assessment_scores.score')
            ->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->where('assessments.is_published', true)->where('assessments.max_score', '>', 0)
            ->groupBy('assessments.subject_id')
            ->selectRaw('assessments.subject_id as subject_id, avg(assessment_scores.score * 100.0 / assessments.max_score) as average')->get()
            ->map(fn ($r) => ['id' => $r->subject_id, 'name_ar' => $subjects[$r->subject_id] ?? '', 'name_en' => $english[$r->subject_id] ?? '', 'average' => round((float) $r->average, 1)])
            ->sortBy('name_ar')->values()->all();
    }

    /** Students with 3 or more absences in the last 7 days. */
    private function frequentAbsences(): array
    {
        $rows = StudentAttendance::with('student:id,name_ar,name_en')->whereDate('date', '>=', now()->subDays(6)->toDateString())->where('status', 'absent')->get(['student_id', 'status']);

        return $rows->groupBy('student_id')->filter(fn ($g) => $g->count() >= 3)->map(fn ($g) => ['id' => $g->first()->student_id, 'name_ar' => $g->first()->student?->name_ar, 'name_en' => $g->first()->student?->name_en, 'days' => $g->count()])
            ->sortByDesc('days')->take(5)->values()->all();
    }
}
