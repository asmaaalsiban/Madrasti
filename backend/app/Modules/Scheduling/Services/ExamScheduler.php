<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Term;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Scheduling\Models\ExamSession;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds the exam timetable of a term. Every section of a grade sits the same subject at the same time; a grade never has more
 * than `grade_per_day` exams in a day; and each room gets teachers to watch it who are not busy in another room at that time
 * and do not teach that subject to that section. Nothing is saved until `apply`.
 */
class ExamScheduler
{
    public function __construct(private readonly PeriodSetup $periods, private readonly Notifier $notifier) {}

    /**
     * @param  array{from: string, to: string, exclude_dates?: list<string>, start_time: string, duration_minutes: int, gap_minutes?: int, slots_per_day: int, grade_per_day: int, invigilators: int, grade_level_ids?: list<int>|null}  $o
     * @return array{rows: list<array<string, mixed>>, warnings: list<string>, days: int, grades: int, classroom_ids: list<int>}
     */
    public function plan(Term $term, array $o): array
    {
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
        $placed = []; // [classroom_id, subject_id, date, start, end]
        foreach ($grades as $grade) {
            $mine = $this->gradeSlots($slots, $o['grade_per_day']);
            $subjects = $subjectsByGrade[$grade->id];
            if ($subjects->count() > count($mine)) {
                throw ValidationException::withMessages(['to' => [__('exams.not_enough_days', ['grade' => $grade->name_ar, 'needed' => $subjects->count(), 'available' => count($mine)])]]);
            }
            foreach ($subjects->values() as $i => $subject) {
                foreach ($rooms[$grade->id] as $room) {
                    $placed[] = ['classroom' => $room, 'subject_id' => (int) $subject->subject_id, 'date' => $mine[$i]['date'], 'start' => $mine[$i]['start'], 'end' => $mine[$i]['end']];
                }
            }
        }

        // 2. Who watches: in each time slot, teachers not busy elsewhere, not the subject's own teacher, those who know the section first, fewest duties first.
        $teachers = User::where('role', Role::Teacher)->where('is_active', true)->whereHas('staffProfile', fn ($q) => $q->whereIn('staff_type', collect(StaffType::cases())->filter(fn ($t) => $t->category() === 'teaching')->map->value->all()))->orderBy('name')->get(['id', 'name']);
        $ownTeacher = DB::table('classroom_subject_teacher')->get()->mapWithKeys(fn ($r) => [$r->classroom_id.'-'.$r->subject_id => (int) $r->teacher_id]);
        $knows = DB::table('classroom_subject_teacher')->get()->groupBy('classroom_id')->map(fn ($g) => $g->pluck('teacher_id')->map(fn ($v) => (int) $v)->flip());
        $duties = $teachers->pluck('id')->mapWithKeys(fn ($id) => [$id => 0])->all();
        $busy = [];
        $warnings = [];
        $rows = [];
        foreach (collect($placed)->groupBy(fn ($p) => $p['date'].' '.$p['start'])->sortKeys() as $key => $group) {
            foreach ($group as $p) {
                $picked = [];
                $own = $ownTeacher[$p['classroom']->id.'-'.$p['subject_id']] ?? null;
                $free = $teachers->filter(fn ($t) => $t->id !== $own && ! isset($busy[$key][$t->id]))
                    ->sortBy(fn ($t) => [isset($knows[$p['classroom']->id][$t->id]) ? 0 : 1, $duties[$t->id], $t->id])->values();
                foreach ($free->take($o['invigilators']) as $t) {
                    $picked[] = $t->id;
                    $busy[$key][$t->id] = true;
                    $duties[$t->id]++;
                }
                if (count($picked) < $o['invigilators']) {
                    $warnings[] = __('exams.short_of_teachers', ['class' => $p['classroom']->name, 'date' => $p['date'], 'got' => count($picked), 'needed' => $o['invigilators']]);
                }
                $rows[] = ['classroom_id' => $p['classroom']->id, 'subject_id' => $p['subject_id'], 'exam_date' => $p['date'], 'starts_at' => $p['start'], 'ends_at' => $p['end'], 'invigilator_ids' => $picked];
            }
        }

        return ['rows' => $rows, 'warnings' => array_values(array_unique($warnings)), 'days' => collect($rows)->pluck('exam_date')->unique()->count(), 'grades' => $grades->count(), 'classroom_ids' => collect($rows)->pluck('classroom_id')->unique()->values()->all()];
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
     * The slots of the whole period: each working day (not excluded) split into `slots_per_day` exam times.
     *
     * @param  array<string, mixed>  $o
     * @return list<array{date: string, start: string, end: string}>
     */
    private function slots(array $o): array
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
                $end = $start->copy()->addMinutes($o['duration_minutes']);
                $slots[] = ['date' => $d->toDateString(), 'start' => $start->format('H:i:s'), 'end' => $end->format('H:i:s')];
                $start = $end->copy()->addMinutes($o['gap_minutes'] ?? 15);
            }
        }

        return $slots;
    }

    /**
     * What one grade can use: the first `$perDay` slots of every day.
     *
     * @param  list<array{date: string, start: string, end: string}>  $slots
     * @return list<array{date: string, start: string, end: string}>
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
