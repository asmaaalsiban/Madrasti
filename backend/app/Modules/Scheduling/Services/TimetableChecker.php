<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Services\TeacherPlanner;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use Illuminate\Support\Facades\DB;

/**
 * Looks at the whole timetable and says what is wrong, before the week starts. Each issue is
 * `{type, severity, classroom_id?, teacher_id?, subject_id?, period_id?, params}`; the screen writes the sentence.
 * Severity: error (a rule is broken), warning (probably not what the school wants), info (worth knowing).
 */
class TimetableChecker
{
    public function __construct(private readonly TeacherPlanner $planner) {}

    /** @return array{issues: list<array<string, mixed>>, counts: array{error: int, warning: int, info: int}} */
    public function check(): array
    {
        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            return ['issues' => [], 'counts' => ['error' => 0, 'warning' => 0, 'info' => 0]];
        }
        $classrooms = Classroom::where('academic_year_id', $year->id)->with(['gradeLevel', 'homeroomTeacher'])->get()->keyBy('id');
        $assignments = TeachingAssignment::whereIn('classroom_id', $classrooms->keys())->with(['teacher.staffProfile.gradeFrom', 'teacher.staffProfile.gradeTo', 'teacher.staffProfile.classrooms', 'teacher.staffProfile.extraSubjects.gradeFrom', 'teacher.staffProfile.extraSubjects.gradeTo', 'subject'])->get();
        $entries = TimetableEntry::where('academic_year_id', $year->id)->get();
        $periods = Period::all()->keyBy('id');
        $names = DB::table('users')->whereIn('id', $assignments->pluck('teacher_id')->merge($entries->pluck('teacher_id'))->unique())->pluck('name', 'id');
        $unavailable = DB::table('teacher_unavailabilities')->get()->groupBy('teacher_id')->map(fn ($rows) => $rows->pluck('period_id')->flip());
        $issues = [];
        $add = function (string $type, string $severity, array $where, array $params = []) use (&$issues): void {
            $issues[] = ['type' => $type, 'severity' => $severity, 'params' => $params] + $where;
        };

        // 1. A teacher in two places at the same time (the database forbids it, so this finds old or imported data).
        foreach ($entries->groupBy(fn ($e) => $e->teacher_id.'-'.$e->period_id) as $group) {
            if ($group->count() > 1) {
                $e = $group->first();
                $add('teacher_double_booked', 'error', ['teacher_id' => $e->teacher_id, 'period_id' => $e->period_id], ['teacher' => $names[$e->teacher_id] ?? '', 'classrooms' => $group->map(fn ($x) => $classrooms[$x->classroom_id]->name ?? '')->implode(' / ')]);
            }
        }

        // 2. What is taught, against what was planned: every assignment against the lessons placed for it.
        $placed = $entries->groupBy(fn ($e) => $e->classroom_id.'-'.$e->subject_id)->map->count();
        foreach ($assignments as $a) {
            $room = $classrooms[$a->classroom_id];
            $have = (int) ($placed[$a->classroom_id.'-'.$a->subject_id] ?? 0);
            $params = ['classroom' => $room->name, 'subject' => $a->subject->name_en, 'subject_ar' => $a->subject->name_ar, 'planned' => $a->weekly_periods, 'placed' => $have];
            $where = ['classroom_id' => $a->classroom_id, 'subject_id' => $a->subject_id, 'teacher_id' => $a->teacher_id];
            if ($have < $a->weekly_periods) {
                $add('periods_missing', 'warning', $where, $params + ['count' => $a->weekly_periods - $have]);
            } elseif ($have > $a->weekly_periods) {
                $add('periods_extra', 'error', $where, $params + ['count' => $have - $a->weekly_periods]);
            }
            $profile = $a->teacher?->staffProfile;
            if ($profile !== null && ! $profile->canTeachSubject((int) $a->subject_id, (int) $room->gradeLevel->sort_order, $room->gender ?? 'mixed', $room->id)) {
                $add('teacher_not_qualified', 'error', $where, ['teacher' => $a->teacher->name] + $params);
            }
        }

        // 3. A subject of the curriculum nobody teaches in a section.
        $curriculum = GradeLevelSubject::get()->groupBy('grade_level_id');
        $subjectNames = \App\Modules\Academic\Models\Subject::get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $assigned = $assignments->groupBy('classroom_id')->map(fn ($rows) => $rows->pluck('subject_id')->flip());
        foreach ($classrooms as $room) {
            foreach ($curriculum->get($room->grade_level_id, collect()) as $cell) {
                if (! isset(($assigned[$room->id] ?? collect())[$cell->subject_id])) {
                    $add('subject_without_teacher', 'warning', ['classroom_id' => $room->id, 'subject_id' => $cell->subject_id], ['classroom' => $room->name, 'subject' => $subjectNames[$cell->subject_id]->name_en, 'subject_ar' => $subjectNames[$cell->subject_id]->name_ar]);
                }
            }
            if ($room->homeroom_teacher_id === null) {
                $add('no_homeroom', 'info', ['classroom_id' => $room->id], ['classroom' => $room->name]);
            }
        }

        // 4. Lessons placed against the rules.
        $teacherOf = $assignments->mapWithKeys(fn ($a) => [$a->classroom_id.'-'.$a->subject_id => $a->teacher_id]);
        foreach ($entries as $e) {
            $room = $classrooms[$e->classroom_id] ?? null;
            $period = $periods[$e->period_id] ?? null;
            if ($room === null || $period === null) {
                continue;
            }
            $where = ['classroom_id' => $e->classroom_id, 'teacher_id' => $e->teacher_id, 'subject_id' => $e->subject_id, 'period_id' => $e->period_id];
            $owner = $teacherOf[$e->classroom_id.'-'.$e->subject_id] ?? null;
            if ($owner === null || $owner !== $e->teacher_id) {
                $add('lesson_without_assignment', 'error', $where, ['classroom' => $room->name, 'teacher' => $names[$e->teacher_id] ?? '']);
            }
            if (isset(($unavailable[$e->teacher_id] ?? collect())[$e->period_id])) {
                $add('teacher_unavailable', 'error', $where, ['classroom' => $room->name, 'teacher' => $names[$e->teacher_id] ?? '', 'day' => $period->day_of_week, 'period' => $period->number]);
            }
            $limit = $room->gradeLevel?->periods_per_day;
            if ($limit !== null && $period->number > $limit) {
                $add('beyond_grade_periods', 'error', $where, ['classroom' => $room->name, 'day' => $period->day_of_week, 'period' => $period->number, 'limit' => $limit]);
            }
        }

        // 5. Teachers: over the weekly load, and many free periods between lessons.
        $max = $this->planner->maxLoad();
        foreach ($assignments->groupBy('teacher_id') as $teacherId => $rows) {
            $load = (int) $rows->sum('weekly_periods');
            if ($load > $max) {
                $add('teacher_overloaded', 'warning', ['teacher_id' => (int) $teacherId], ['teacher' => $names[$teacherId] ?? '', 'load' => $load, 'max' => $max]);
            }
        }
        foreach ($entries->groupBy('teacher_id') as $teacherId => $rows) {
            $gaps = 0;
            foreach ($rows->groupBy(fn ($e) => $periods[$e->period_id]->day_of_week ?? 0) as $day) {
                $numbers = $day->map(fn ($e) => $periods[$e->period_id]->number ?? 0)->unique()->sort()->values();
                $gaps += max(0, ($numbers->last() - $numbers->first() + 1) - $numbers->count());
            }
            if ($gaps >= 5) {
                $add('teacher_gaps', 'info', ['teacher_id' => (int) $teacherId], ['teacher' => $names[$teacherId] ?? '', 'gaps' => $gaps]);
            }
        }

        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, fn ($a, $b) => [$order[$a['severity']], $a['type']] <=> [$order[$b['severity']], $b['type']]);
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($issues as $i) {
            $counts[$i['severity']]++;
        }

        return ['issues' => $issues, 'counts' => $counts];
    }
}
