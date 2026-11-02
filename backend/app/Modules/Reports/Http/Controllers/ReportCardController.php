<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Grading\Services\StudentTranscript;
use App\Modules\Reports\Models\Signature;
use App\Modules\TeacherTools\Models\BehaviorEvaluation;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The student's report card for the current academic year: every term's published grades by subject with the annual
 * result, the term and annual averages, attendance and behaviour. Same access as the grades: the admin, the student, the parents.
 */
class ReportCardController extends Controller
{
    public function __invoke(Student $student, StudentTranscript $transcript): JsonResponse
    {
        Gate::authorize('view-student-grades', $student);
        /** @var User $user */
        $user = auth()->user();

        $year = AcademicYear::where('is_current', true)->first();
        $enrollment = $year ? $student->enrollments()->where('academic_year_id', $year->id)->with(['classroom.gradeLevel', 'classroom.homeroomTeacher:id,name'])->first() : null;
        $terms = $year && $enrollment ? $transcript->terms($student, $year, $enrollment) : [];
        $pass = (float) config('grading.pass_percent');

        // Subject by subject: the percent of each term and the annual one (the mean of the terms that have a percent).
        $subjects = [];
        foreach ($terms as $term) {
            foreach ($term['subjects'] as $row) {
                $id = $row['subject']['id'];
                $subjects[$id] ??= ['subject' => $row['subject'], 'terms' => []];
                $subjects[$id]['terms'][$term['id']] = ['percent' => $row['percent'], 'result' => $row['result']];
            }
        }
        $subjects = collect($subjects)->map(function (array $s) use ($pass): array {
            $percents = collect($s['terms'])->pluck('percent')->filter(fn ($p) => $p !== null);
            $annual = $percents->isEmpty() ? null : round($percents->avg(), 1);
            $waiting = collect($s['terms'])->contains(fn ($t) => in_array($t['result'], ['makeup', 'retake'], true));

            return $s + ['annual' => $annual, 'annual_result' => match (true) {
                $waiting => 'pending', $annual === null => 'none', $annual >= $pass => 'pass', default => 'fail',
            }];
        })->sortBy(fn ($s) => $s['subject']['name_en'])->values();

        $average = fn ($percents) => ($p = collect($percents)->filter(fn ($v) => $v !== null)) && $p->isNotEmpty() ? round($p->avg(), 1) : null;

        $attendance = StudentAttendance::where('student_id', $student->id)->when($year, fn ($q) => $q->whereBetween('date', [$year->starts_on->format('Y-m-d'), $year->ends_on->format('Y-m-d')]))->get();
        $evaluations = BehaviorEvaluation::with('scores')->where('student_id', $student->id)
            ->when(! $user->hasRole(Role::Admin), fn ($q) => $q->where('visible_to_parent', true))->get();

        // Electronic signatures for the bottom of the card: the section's homeroom teacher (if they uploaded one) and the school's.
        $teacher = $enrollment?->classroom?->homeroomTeacher;
        $homeroom = $teacher ? Signature::where('scope', 'user')->where('user_id', $teacher->id)->where('is_active', true)->first() : null;

        return response()->json(['data' => [
            'signatures' => [
                'homeroom' => $teacher ? ['name' => $teacher->name, 'image' => $homeroom?->dataUri()] : null,
                'school' => Signature::where('scope', 'school')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
                    ->map(fn (Signature $sig) => ['id' => $sig->id, 'title' => $sig->title, 'name' => $sig->signer_name, 'image' => $sig->dataUri()])->values(),
            ],
            'student' => $student->only(['id', 'student_number', 'name_ar', 'name_en', 'birth_date']) + [
                'classroom' => $enrollment?->classroom?->name,
                'grade' => $enrollment?->classroom?->gradeLevel?->only(['name_ar', 'name_en']),
            ],
            'year' => $year ? ['name' => $year->name, 'starts_on' => $year->starts_on->format('Y-m-d'), 'ends_on' => $year->ends_on->format('Y-m-d')] : null,
            'pass_percent' => $pass,
            'terms' => collect($terms)->map(function (array $term) use ($attendance, $evaluations, $average): array {
                $from = $term['starts_on'];
                $to = $term['ends_on'];
                $days = $attendance->filter(fn ($a) => $a->date->format('Y-m-d') >= $from && $a->date->format('Y-m-d') <= $to);
                $scores = $evaluations->filter(fn ($e) => $e->evaluated_on->format('Y-m-d') >= $from && $e->evaluated_on->format('Y-m-d') <= $to)->flatMap->scores->pluck('score');

                return [
                    'id' => $term['id'], 'name' => $term['name'], 'starts_on' => $from, 'ends_on' => $to,
                    'average' => $average($term['subjects']->pluck('percent')),
                    'absent' => $days->filter(fn ($a) => $a->status->value === 'absent')->count(),
                    'late' => $days->filter(fn ($a) => $a->status->value === 'late')->count(),
                    'behavior' => $scores->isEmpty() ? null : round($scores->avg(), 1),
                ];
            })->values(),
            'subjects' => $subjects,
            'annual_average' => $average($subjects->pluck('annual')),
            'attendance' => [
                'absent' => $attendance->filter(fn ($a) => $a->status->value === 'absent')->count(),
                'late' => $attendance->filter(fn ($a) => $a->status->value === 'late')->count(),
                'excused' => $attendance->filter(fn ($a) => $a->status->value === 'excused')->count(),
                'days' => $attendance->pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->unique()->count(),
            ],
            'behavior' => $evaluations->flatMap->scores->isEmpty() ? null : round($evaluations->flatMap->scores->pluck('score')->avg(), 1),
        ]]);
    }
}
