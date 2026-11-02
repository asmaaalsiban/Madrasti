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
     * @param  array{from: string, to: string, exclude_dates?: list<string>, first_period: int, periods_per_exam: int, slots_per_day: int, grade_per_day: int, fill_missing?: bool, grade_level_ids?: list<int>|null}  $o
     * @return array{rows: list<array<string, mixed>>, warnings: list<string>, days: int, grades: int, classroom_ids: list<int>}
     */
    public function plan(Term $term, array $o): array
    {
        if (! Period::exists()) {
            throw ValidationException::withMessages(['first_period' => [__('scheduling.no_periods')]]);
        }
        $slots = $this->slots($o);
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

        // 2. Who watches: whoever has the section in those periods on the timetable.
        $teachers = User::where('role', Role::Teacher)->where('is_active', true)->whereHas('staffProfile', fn ($q) => $q->whereIn('staff_type', collect(StaffType::cases())->filter(fn ($t) => $t->category() === 'teaching')->map->value->all()))->orderBy('name')->get(['id', 'name']);
        $duties = $teachers->pluck('id')->mapWithKeys(fn ($id) => [$id => 0])->all();
        $taken = []; // slot key => teacher ids already used as a stand-in
        $warnings = [];
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
                $stand = ($o['fill_missing'] ?? true) ? $this->freeTeacher($teachers, $duties, $taken[$key] ?? [], $slot['dow'], $number) : null;
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
            ExamSession::where('term_id', $term->id)->whereIn('classroom_id', $plan['classroom_ids'])->delete();
            foreach ($plan['rows'] as $row) {
                $session = ExamSession::create(['term_id' => $term->id] + collect($row)->except('invigilator_ids')->all());
                $session->invigilators()->sync($row['invigilator_ids']);
            }
        });
        foreach ($plan['classroom_ids'] as $classroomId) {
            $this->notifier->toSection($classroomId, 'exams', 'exam_schedule', ['title' => $term->name]);
        }
        $teachers = collect($plan['rows'])->pluck('invigilator_ids')->flatten()->unique();
        $this->notifier->toUsers($teachers, 'exam_duty', ['title' => $term->name], '/teacher/exams');
    }

    /**
     * The exam times of the whole period: each working day (not excluded) gets `slots_per_day` exams, one after another, each
     * covering `periods_per_exam` consecutive periods from `first_period`; the times are the school's own period times.
     *
     * @param  array<string, mixed>  $o
     * @return list<array{date: string, dow: int, period_from: int, period_to: int, start: string, end: string}>
     */
    private function slots(array $o): array
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
