<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Term;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Scheduling\Models\ExamSession;
use App\Modules\Scheduling\Models\Period;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds the exam timetable of a term. An exam takes the place of regular lessons: it sits in the school's own periods
 * (say periods 2 and 3), and the teacher who already has that section in those periods watches it, automatically. Every
 * section of a grade sits the same subject together; a grade never has more than `grade_per_day` exams in a day. A period
 * where the section has no teacher on the timetable gets a free teacher instead (or a warning). Nothing is saved until `apply`.
 */
class ExamScheduler
{
    public function __construct(private readonly PeriodSetup $periods, private readonly Notifier $notifier) {}

    /**
     * @param  array<string, mixed>  $o  kind (monthly|final), round, from, to, exclude_dates, slots_per_day, grade_per_day, grade_level_ids and, for monthly: first_period, periods_per_exam, fill_missing; for final: start_time, duration_minutes, gap_minutes, invigilators
     * @return array{rows: list<array<string, mixed>>, warnings: list<string>, days: int, grades: int, classroom_ids: list<int>}
     */
    public function plan(Term $term, array $o): array
    {
        $final = ($o['kind'] ?? 'monthly') === 'final';
        $round = $final ? 0 : (int) ($o['round'] ?? 1);
        if (! $final && ! Period::exists()) {
            throw ValidationException::withMessages(['first_period' => [__('scheduling.no_periods')]]);
        }
        $slots = $final ? $this->timeSlots($o) : $this->periodSlots($o);
        if ($slots === []) {
            throw ValidationException::withMessages(['from' => [__('exams.no_days')]]);
        }
        $subjectsByGrade = DB::table('grade_level_subjects')->orderBy('subject_id')->get()->groupBy('grade_level_id');
        $rooms = Classroom::where('academic_year_id', $term->academic_year_id)->when($o['grade_level_ids'] ?? null, fn ($q, $ids) => $q->whereIn('grade_level_id', $ids))->orderBy('name')->get()->groupBy('grade_level_id');
        $grades = GradeLevel::whereIn('id', $rooms->keys())->orderBy('sort_order')->get()->filter(fn ($g) => $subjectsByGrade->has($g->id));
        if ($grades->isEmpty()) {
            throw ValidationException::withMessages(['grade_level_ids' => [__('exams.nothing_to_schedule')]]);
        }

        // 1. When: the subjects of each grade take its slots in order, at most `grade_per_day` a day.
        $placed = [];
        foreach ($grades as $grade) {
            $mine = $this->gradeSlots($slots, $o['grade_per_day']);
            $subjects = $subjectsByGrade[$grade->id];
            if ($subjects->count() > count($mine)) {
                throw ValidationException::withMessages(['to' => [__('exams.not_enough_days', ['grade' => $grade->name_ar, 'needed' => $subjects->count(), 'available' => count($mine)])]]);
            }
            foreach ($subjects->values() as $i => $subject) {
                foreach ($rooms[$grade->id] as $room) {
                    $placed[] = ['classroom' => $room, 'subject_id' => (int) $subject->subject_id, 'slot' => $mine[$i]];
                }
            }
        }

        $teachers = User::where('role', Role::Teacher)->where('is_active', true)->whereHas('staffProfile', fn ($q) => $q->whereIn('staff_type', collect(StaffType::cases())->filter(fn ($t) => $t->category() === 'teaching')->map->value->all()))->orderBy('name')->get(['id', 'name']);
        $duties = $teachers->pluck('id')->mapWithKeys(fn ($id) => [$id => 0])->all();
        $warnings = [];
        $rows = $final ? $this->finalWatchers($placed, $teachers, $duties, (int) ($o['invigilators'] ?? 1), $warnings) : $this->monthlyWatchers($placed, $teachers, $duties, (bool) ($o['fill_missing'] ?? true), $warnings);
        $rows = array_map(fn ($r) => $r + ['kind' => $final ? 'final' : 'monthly', 'round' => $round], $rows);

        return ['rows' => $rows, 'warnings' => array_values(array_unique($warnings)), 'days' => collect($rows)->pluck('exam_date')->unique()->count(), 'grades' => $grades->count(), 'classroom_ids' => collect($rows)->pluck('classroom_id')->unique()->values()->all()];
    }

    /**
     * The school's own times for a run of periods on a day (null when the day has no such periods).
     *
     * @return array{start: string, end: string}|null
     */
    public function timesFor(string $date, int $from, int $to): ?array
    {
        $rows = Period::where('is_break', false)->where('day_of_week', Carbon::parse($date)->dayOfWeek)->whereBetween('number', [$from, $to])->orderBy('number')->get();

        return $rows->count() === $to - $from + 1 ? ['start' => $rows->first()->starts_at, 'end' => $rows->last()->ends_at] : null;
    }

    /**
     * The teachers the timetable gives this section in those periods of that day.
     *
     * @return list<int>
     */
    public function watchersFor(Classroom $room, string $date, int $from, int $to): array
    {
        $dow = Carbon::parse($date)->dayOfWeek;
        $ids = [];
        foreach (range($from, $to) as $number) {
            $id = $this->teacherOf($room, $dow, $number);
            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Monthly exams: whoever has the section in those periods on the timetable watches; a period without a teacher gets a free one.
     *
     * @param  list<array<string, mixed>>  $placed
     * @param  Collection<int, User>  $teachers
     * @param  array<int, int>  $duties
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function monthlyWatchers(array $placed, Collection $teachers, array $duties, bool $fillMissing, array &$warnings): array
    {
        $taken = []; // slot key => teacher ids already used as a stand-in
        $rows = [];
        foreach ($placed as $p) {
            $slot = $p['slot'];
            $key = $slot['date'].' '.$slot['start'];
            $watchers = [];
            $missing = [];
            foreach (range($slot['period_from'], $slot['period_to']) as $number) {
                $teacher = $this->teacherOf($p['classroom'], $slot['dow'], $number);
                $teacher === null ? $missing[] = $number : $watchers[$teacher] = true;
            }
            foreach ($missing as $number) {
                $stand = $fillMissing ? $this->freeTeacher($teachers, $duties, $taken[$key] ?? [], $slot['dow'], $number) : null;
                if ($stand === null) {
                    $warnings[] = __('exams.nobody_for_period', ['class' => $p['classroom']->name, 'date' => $slot['date'], 'period' => $number]);

                    continue;
                }
                $watchers[$stand] = true;
                $taken[$key][] = $stand;
                $duties[$stand]++;
            }
            $rows[] = ['classroom_id' => $p['classroom']->id, 'subject_id' => $p['subject_id'], 'exam_date' => $slot['date'], 'period_from' => $slot['period_from'], 'period_to' => $slot['period_to'], 'starts_at' => $slot['start'], 'ends_at' => $slot['end'], 'invigilator_ids' => array_keys($watchers)];
        }


        return $rows;
    }

    /**
     * Final exams: students come only for the exam, so any teacher can watch: free at that time, not the subject's own teacher,
     * those who know the section first, fewest duties first.
     *
     * @param  list<array<string, mixed>>  $placed
     * @param  Collection<int, User>  $teachers
     * @param  array<int, int>  $duties
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function finalWatchers(array $placed, Collection $teachers, array $duties, int $needed, array &$warnings): array
    {
        $ownTeacher = DB::table('classroom_subject_teacher')->get()->mapWithKeys(fn ($r) => [$r->classroom_id.'-'.$r->subject_id => (int) $r->teacher_id]);
        $knows = DB::table('classroom_subject_teacher')->get()->groupBy('classroom_id')->map(fn ($g) => $g->pluck('teacher_id')->map(fn ($v) => (int) $v)->flip());
        $busy = [];
        $rows = [];
        foreach ($placed as $p) {
            $slot = $p['slot'];
            $key = $slot['date'].' '.$slot['start'];
            $own = $ownTeacher[$p['classroom']->id.'-'.$p['subject_id']] ?? null;
            $picked = [];
            $free = $teachers->filter(fn ($t) => $t->id !== $own && ! isset($busy[$key][$t->id]))->sortBy(fn ($t) => [isset($knows[$p['classroom']->id][$t->id]) ? 0 : 1, $duties[$t->id], $t->id])->values();
            foreach ($free->take($needed) as $t) {
                $picked[] = $t->id;
                $busy[$key][$t->id] = true;
                $duties[$t->id]++;
            }
            if (count($picked) < $needed) {
                $warnings[] = __('exams.short_of_teachers', ['class' => $p['classroom']->name, 'date' => $slot['date'], 'got' => count($picked), 'needed' => $needed]);
            }
            $rows[] = ['classroom_id' => $p['classroom']->id, 'subject_id' => $p['subject_id'], 'exam_date' => $slot['date'], 'period_from' => null, 'period_to' => null, 'starts_at' => $slot['start'], 'ends_at' => $slot['end'], 'invigilator_ids' => $picked];
        }

        return $rows;
    }

    /**
     * The exam times of the whole period for final exams: each working day gets `slots_per_day` exams from `start_time`, each as long as `duration_minutes`.
     *
     * @param  array<string, mixed>  $o
     * @return list<array{date: string, dow: int, period_from: null, period_to: null, start: string, end: string}>
     */
    private function timeSlots(array $o): array
    {
        $days = array_map('intval', $this->periods->current()['days']);
        $exclude = collect($o['exclude_dates'] ?? [])->flip();
        $slots = [];
        for ($d = Carbon::parse($o['from']); $d->lte(Carbon::parse($o['to'])); $d->addDay()) {
            if (! in_array($d->dayOfWeek, $days, true) || $exclude->has($d->toDateString())) {
                continue;
            }
            $start = Carbon::parse($d->toDateString().' '.$o['start_time']);
            for ($i = 0; $i < $o['slots_per_day']; $i++) {
                $end = $start->copy()->addMinutes((int) $o['duration_minutes']);
                $slots[] = ['date' => $d->toDateString(), 'dow' => $d->dayOfWeek, 'period_from' => null, 'period_to' => null, 'start' => $start->format('H:i:s'), 'end' => $end->format('H:i:s')];
                $start = $end->copy()->addMinutes((int) ($o['gap_minutes'] ?? 15));
            }
        }

        return $slots;
    }

    /** The teacher the timetable gives this section in that period of that weekday. */
    private function teacherOf(Classroom $room, int $dow, int $number): ?int
    {
        $id = DB::table('timetable_entries')->join('periods', 'periods.id', '=', 'timetable_entries.period_id')
            ->where('timetable_entries.classroom_id', $room->id)->where('timetable_entries.academic_year_id', $room->academic_year_id)->where('periods.day_of_week', $dow)->where('periods.number', $number)->value('timetable_entries.teacher_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * A teacher with no lesson in that period and not already standing in elsewhere then: the one with the fewest duties.
     *
     * @param  Collection<int, User>  $teachers
     * @param  array<int, int>  $duties
     * @param  list<int>  $taken
     */
    private function freeTeacher(Collection $teachers, array $duties, array $taken, int $dow, int $number): ?int
    {
        $teaching = DB::table('timetable_entries')->join('periods', 'periods.id', '=', 'timetable_entries.period_id')->where('periods.day_of_week', $dow)->where('periods.number', $number)->pluck('timetable_entries.teacher_id')->map(fn ($v) => (int) $v)->flip();
        $free = $teachers->filter(fn ($t) => ! $teaching->has($t->id) && ! in_array($t->id, $taken, true))->sortBy(fn ($t) => [$duties[$t->id], $t->id])->first();

        return $free?->id;
    }

    /**
     * Replaces the exams of these sections in the term with the plan, and tells students, parents and invigilators.
     *
     * @param  array{rows: list<array<string, mixed>>, classroom_ids: list<int>}  $plan
     */
    public function apply(Term $term, array $plan): void
    {
        DB::transaction(function () use ($term, $plan): void {
            $kind = $plan['rows'][0]['kind'] ?? 'monthly';
            $round = $plan['rows'][0]['round'] ?? 1;
            ExamSession::where('term_id', $term->id)->where('kind', $kind)->where('round', $round)->whereIn('classroom_id', $plan['classroom_ids'])->delete();
            foreach ($plan['rows'] as $row) {
                $session = ExamSession::create(['term_id' => $term->id] + collect($row)->except('invigilator_ids')->all());
                $session->invigilators()->sync($row['invigilator_ids']);
            }
        });
        $first = $plan['rows'][0] ?? ['kind' => 'monthly', 'round' => 1];
        $title = $term->name.' — '.($first['kind'] === 'final' ? __('exams.kind_final') : __('exams.kind_monthly', ['n' => $first['round']]));
        foreach ($plan['classroom_ids'] as $classroomId) {
            $this->notifier->toSection($classroomId, 'exams', 'exam_schedule', ['title' => $title]);
        }
        $teachers = collect($plan['rows'])->pluck('invigilator_ids')->flatten()->unique();
        $this->notifier->toUsers($teachers, 'exam_duty', ['title' => $title], '/teacher/exams');
    }

    /**
     * The exam times of the whole period: each working day (not excluded) gets `slots_per_day` exams, one after another, each
     * covering `periods_per_exam` consecutive periods from `first_period`; the times are the school's own period times.
     *
     * @param  array<string, mixed>  $o
     * @return list<array{date: string, dow: int, period_from: int, period_to: int, start: string, end: string}>
     */
    private function periodSlots(array $o): array
    {
        $days = array_map('intval', $this->periods->current()['days']);
        $exclude = collect($o['exclude_dates'] ?? [])->flip();
        $byDay = Period::where('is_break', false)->orderBy('number')->get()->groupBy('day_of_week');
        $slots = [];
        for ($d = Carbon::parse($o['from']); $d->lte(Carbon::parse($o['to'])); $d->addDay()) {
            if (! in_array($d->dayOfWeek, $days, true) || $exclude->has($d->toDateString())) {
                continue;
            }
            $today = ($byDay[$d->dayOfWeek] ?? collect())->keyBy('number');
            for ($i = 0; $i < $o['slots_per_day']; $i++) {
                $from = $o['first_period'] + $i * $o['periods_per_exam'];
                $to = $from + $o['periods_per_exam'] - 1;
                if (! $today->has($from) || ! $today->has($to)) {
                    continue; // the school day is shorter than that
                }
                $slots[] = ['date' => $d->toDateString(), 'dow' => $d->dayOfWeek, 'period_from' => $from, 'period_to' => $to, 'start' => $today[$from]->starts_at, 'end' => $today[$to]->ends_at];
            }
        }

        return $slots;
    }

    /**
     * What one grade can use: the first `$perDay` slots of every day.
     *
     * @param  list<array{date: string, dow: int, period_from: int, period_to: int, start: string, end: string}>  $slots
     * @return list<array{date: string, dow: int, period_from: int, period_to: int, start: string, end: string}>
     */
    private function gradeSlots(array $slots, int $perDay): array
    {
        $count = [];
        $mine = [];
        foreach ($slots as $s) {
            $count[$s['date']] = ($count[$s['date']] ?? 0) + 1;
            if ($count[$s['date']] <= $perDay) {
                $mine[] = $s;
            }
        }

        return $mine;
    }

    /** A session another one overlaps for the same section (used when an exam is moved by hand). */
    public function clash(ExamSession $session, string $date, string $start, string $end): ?ExamSession
    {
        return ExamSession::where('classroom_id', $session->classroom_id)->where('term_id', $session->term_id)->whereKeyNot($session->id)->whereDate('exam_date', $date)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->first();
    }

    /** @param  Collection<int, int>  $userIds  teachers already watching another room at this time. */
    public function busyInvigilators(ExamSession $session, string $date, string $start, string $end): Collection
    {
        return DB::table('exam_invigilators')->join('exam_sessions', 'exam_sessions.id', '=', 'exam_invigilators.exam_session_id')
            ->where('exam_sessions.id', '!=', $session->id)->whereDate('exam_sessions.exam_date', $date)->where('exam_sessions.starts_at', '<', $end)->where('exam_sessions.ends_at', '>', $start)->pluck('exam_invigilators.user_id');
    }
}
