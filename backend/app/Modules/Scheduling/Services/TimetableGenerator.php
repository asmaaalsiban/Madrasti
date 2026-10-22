<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Services\TeacherPlanner;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the timetable of every section of the current year from the teacher assignments (subject, teacher, periods a week).
 *
 * Hard rules: a section has one lesson per period; a teacher is in one place at a time and never in a period they are
 * unavailable; a teacher never has two consecutive periods in different buildings (the break is five minutes); a subject
 * has at most ceil(periods / days) lessons a day. Soft rules: a subject is spread over the days (Arabic, English and maths
 * land on every day), core subjects prefer the morning, a teacher's lessons are spread over the week.
 *
 * It is a constructive search with a one-step repair (move the teacher's other lesson out of the way) and several attempts
 * with different orders; the same seed gives the same result. Locked entries are kept and counted.
 */
class TimetableGenerator
{
    /** Strict attempts keep every daily cap; the last ones may put one more lesson of a subject on a day rather than leave a lesson out. */
    private const STRICT_ATTEMPTS = 60;

    private const RELAXED_ATTEMPTS = 20;

    private const CORE = ['ARB', 'ENG', 'MTH', 'SCI', 'REL'];

    public function __construct(private readonly TeacherPlanner $planner) {}

    /**
     * @return array{entries: list<array<string, int|null>>, report: array<string, mixed>}
     */
    public function plan(AcademicYear $year, int $seed): array
    {
        $ctx = $this->load($year);
        $best = null;
        $attempts = 0;
        for ($i = 0; $i < self::STRICT_ATTEMPTS + self::RELAXED_ATTEMPTS; $i++) {
            $attempts++;
            $result = $this->attempt($ctx, $seed + $i * 7919, $i < self::STRICT_ATTEMPTS ? 0 : 1);
            if ($best === null || count($result['unplaced']) < count($best['unplaced'])) {
                $best = $result;
            }
            if ($best['unplaced'] === []) {
                break;
            }
        }

        $unplaced = collect($best['unplaced'])->groupBy(fn ($u) => $u['classroom'].'|'.$u['subject'])->map(fn (Collection $rows) => [
            'classroom' => $rows->first()['classroom'], 'subject' => $rows->first()['subject'], 'subject_ar' => $rows->first()['subject_ar'], 'count' => $rows->count(),
        ])->values()->all();

        return [
            'entries' => $best['entries'],
            'report' => [
                'incomplete' => $this->incomplete($ctx, $best['entries']),
                'seed' => $seed,
                'attempts' => $attempts,
                'total' => $ctx['total'],
                'placed' => count($best['entries']),
                'locked' => count($ctx['locked']),
                'unplaced' => $unplaced,
                'without_teacher' => $ctx['without_teacher'],
            ],
        ];
    }

    /**
     * Sections that end up with empty periods: fewer lessons (from the curriculum / assignments) than periods in their week.
     *
     * @param  array<string, mixed>  $ctx
     * @param  list<array<string, int|null>>  $entries
     * @return list<array<string, int|string>>
     */
    private function incomplete(array $ctx, array $entries): array
    {
        $lessons = collect($entries)->countBy('classroom_id');
        foreach ($ctx['locked'] as $entry) {
            $lessons[$entry->classroom_id] = ($lessons[$entry->classroom_id] ?? 0) + 1;
        }
        $planned = $ctx['assignments']->pluck('classroom_id')->unique()->flip();
        $result = [];
        foreach ($ctx['classrooms']->sortBy('name') as $classroom) {
            $slots = collect($ctx['slots'])->filter(fn ($s) => $s['number'] <= $ctx['limit'][$classroom->id])->count();
            $count = (int) ($lessons[$classroom->id] ?? 0);
            if ($planned->has($classroom->id) && $count < $slots) {
                $result[] = ['classroom' => $classroom->name, 'lessons' => $count, 'slots' => $slots, 'empty' => $slots - $count];
            }
        }

        return $result;
    }

    /** Replaces the unlocked entries of the year with the planned ones and records the run. */
    public function apply(AcademicYear $year, array $planned, int $by): int
    {
        return DB::transaction(function () use ($year, $planned, $by): int {
            TimetableEntry::where('academic_year_id', $year->id)->where('is_locked', false)->delete();
            $generation = DB::table('schedule_generations')->insertGetId([
                'academic_year_id' => $year->id, 'kind' => 'timetable', 'status' => 'applied', 'constraints' => json_encode(['max_teacher_load' => $this->planner->maxLoad()]),
                'report' => json_encode($planned['report']), 'requested_by' => $by, 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($planned['entries'], 300) as $chunk) {
                DB::table('timetable_entries')->insert(array_map(fn (array $e) => $e + [
                    'academic_year_id' => $year->id, 'schedule_generation_id' => $generation, 'is_locked' => false, 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }

            return $generation;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function load(AcademicYear $year): array
    {
        $periods = Period::orderBy('day_of_week')->orderBy('number')->get();
        $days = $periods->pluck('day_of_week')->unique()->sort()->values()->flip()->all(); // day of week => 0..D-1
        $slots = [];
        $slotOf = []; // "day-number" => slot index
        foreach ($periods as $i => $p) {
            $slots[$i] = ['id' => $p->id, 'day' => $days[$p->day_of_week], 'number' => $p->number];
            $slotOf[$days[$p->day_of_week].'-'.$p->number] = $i;
        }
        $slotById = collect($slots)->mapWithKeys(fn ($s, $i) => [$s['id'] => $i])->all();

        $classrooms = Classroom::where('academic_year_id', $year->id)->with(['room.floor', 'gradeLevel'])->get()->keyBy('id');
        $subjects = Subject::all()->keyBy('id');
        $assignments = TeachingAssignment::whereIn('classroom_id', $classrooms->keys())->get();
        $users = User::whereIn('id', $assignments->pluck('teacher_id'))->get()->keyBy('id');

        $locked = TimetableEntry::where('academic_year_id', $year->id)->where('is_locked', true)->get();
        $unavailable = [];
        foreach (DB::table('teacher_unavailabilities')->get() as $row) {
            if (isset($slotById[$row->period_id])) {
                $unavailable[$row->teacher_id][$slotById[$row->period_id]] = true;
            }
        }

        $assigned = $assignments->mapWithKeys(fn ($a) => [$a->classroom_id.'-'.$a->subject_id => true]);
        $without = $this->planner->demands()->reject(fn ($d) => $assigned->has($d['classroom_id'].'-'.$d['subject_id']))
            ->map(fn ($d) => ['classroom' => $classrooms[$d['classroom_id']]->name, 'subject' => $subjects[$d['subject_id']]->name_en, 'subject_ar' => $subjects[$d['subject_id']]->name_ar, 'periods' => $d['periods']])->values()->all();

        return [
            'slots' => $slots, 'slotOf' => $slotOf, 'slotById' => $slotById, 'dayCount' => count($days),
            // The last period each section has (grades 1-3 and the kindergarten usually have 6 of the school's 7).
            'limit' => $classrooms->map(fn (Classroom $c) => $c->gradeLevel->periods_per_day ?? PHP_INT_MAX)->all(),
            'classrooms' => $classrooms, 'subjects' => $subjects, 'assignments' => $assignments, 'users' => $users,
            'locked' => $locked, 'unavailable' => $unavailable, 'without_teacher' => $without,
            'total' => (int) $assignments->sum('weekly_periods'),
        ];
    }

    /**
     * One attempt: places the lessons in an order that depends on the seed.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{entries: list<array<string, int|null>>, unplaced: list<array<string, mixed>>}
     */
    private function attempt(array $ctx, int $seed, int $slack): array
    {
        mt_srand($seed);
        /** @var array<int, array{id: int, day: int, number: int}> $slots */
        $slots = $ctx['slots'];
        $classBusy = [];   // classroom => slot => placement
        $teacherBusy = []; // teacher => slot => classroom
        $teacherBuilding = []; // teacher => slot => building
        $subjectDay = [];  // classroom => subject => day => count
        $teacherDay = [];  // teacher => day => count
        $placements = [];  // key => [classroom, subject, teacher, slot, building, assignment]
        $buildingOf = fn (int $classroomId) => $ctx['classrooms'][$classroomId]->room?->floor?->building_id;

        $lockedCount = [];
        foreach ($ctx['locked'] as $entry) {
            $slot = $ctx['slotById'][$entry->period_id] ?? null;
            if ($slot === null) {
                continue;
            }
            $classBusy[$entry->classroom_id][$slot] = 'locked';
            $teacherBusy[$entry->teacher_id][$slot] = $entry->classroom_id;
            $teacherBuilding[$entry->teacher_id][$slot] = $buildingOf($entry->classroom_id);
            $subjectDay[$entry->classroom_id][$entry->subject_id][$slots[$slot]['day']] = ($subjectDay[$entry->classroom_id][$entry->subject_id][$slots[$slot]['day']] ?? 0) + 1;
            $teacherDay[$entry->teacher_id][$slots[$slot]['day']] = ($teacherDay[$entry->teacher_id][$slots[$slot]['day']] ?? 0) + 1;
            $lockedCount[$entry->classroom_id.'-'.$entry->subject_id] = ($lockedCount[$entry->classroom_id.'-'.$entry->subject_id] ?? 0) + 1;
        }

        // Teachers with the most periods first (they are the hardest to fit), then the longer lists, with a seeded shuffle for ties.
        $load = $ctx['assignments']->groupBy('teacher_id')->map(fn ($rows) => (int) $rows->sum('weekly_periods'));
        $order = $ctx['assignments']->map(fn ($a) => [
            'a' => $a, 'remaining' => max(0, $a->weekly_periods - ($lockedCount[$a->classroom_id.'-'.$a->subject_id] ?? 0)),
            'key' => $load[$a->teacher_id] * 100 + $a->weekly_periods * 10 + mt_rand(0, 9),
        ])->sortByDesc('key')->values();

        $unplaced = [];
        $state = [&$classBusy, &$teacherBusy, &$teacherBuilding, &$subjectDay, &$teacherDay, &$placements];
        foreach ($order as $item) {
            $a = $item['a'];
            $lesson = [
                'classroom' => $a->classroom_id, 'subject' => $a->subject_id, 'teacher' => $a->teacher_id, 'building' => $buildingOf($a->classroom_id),
                'cap' => (int) ceil($a->weekly_periods / max(1, $ctx['dayCount'])) + $slack,
                'core' => in_array($ctx['subjects'][$a->subject_id]->code, self::CORE, true),
            ];
            for ($k = 0; $k < $item['remaining']; $k++) {
                $slot = $this->bestSlot($ctx, $lesson, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay, $teacherDay);
                if ($slot === null) {
                    $slot = $this->repair($ctx, $lesson, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay, $teacherDay, $placements, $buildingOf);
                }
                if ($slot === null) {
                    $unplaced[] = ['classroom' => $ctx['classrooms'][$a->classroom_id]->name, 'subject' => $ctx['subjects'][$a->subject_id]->name_en, 'subject_ar' => $ctx['subjects'][$a->subject_id]->name_ar];

                    continue;
                }
                $this->place($ctx, $lesson, $slot, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay, $teacherDay, $placements);
            }
        }
        unset($state);

        $entries = [];
        foreach ($placements as $p) {
            $entries[] = [
                'classroom_id' => $p['classroom'], 'period_id' => $slots[$p['slot']]['id'], 'subject_id' => $p['subject'], 'teacher_id' => $p['teacher'],
                'room_id' => $ctx['classrooms'][$p['classroom']]->room_id,
            ];
        }

        return ['entries' => $entries, 'unplaced' => $unplaced];
    }

    /**
     * The cheapest valid slot for a lesson, or null.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $lesson
     */
    private function bestSlot(array $ctx, array $lesson, array &$classBusy, array &$teacherBusy, array &$teacherBuilding, array &$subjectDay, array &$teacherDay, ?int $exceptTeacherSlot = null): ?int
    {
        $best = null;
        $bestCost = PHP_INT_MAX;
        foreach ($ctx['slots'] as $i => $slot) {
            if (! $this->valid($ctx, $lesson, $i, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay)) {
                continue;
            }
            $day = $slot['day'];
            $cost = ($subjectDay[$lesson['classroom']][$lesson['subject']][$day] ?? 0) * 1000 + ($teacherDay[$lesson['teacher']][$day] ?? 0) * 3 + mt_rand(0, 9);
            $cost += $lesson['core'] ? max(0, $slot['number'] - 4) * 15 : max(0, 4 - $slot['number']) * 5;
            if ($cost < $bestCost) {
                $best = $i;
                $bestCost = $cost;
            }
        }

        return $best;
    }

    /**
     * Whether the lesson may go to the slot: the section and the teacher are free, the teacher is available, the subject
     * is not over its daily cap and the teacher does not change building between two consecutive periods.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $lesson
     */
    private function valid(array $ctx, array $lesson, int $slot, array &$classBusy, array &$teacherBusy, array &$teacherBuilding, array &$subjectDay): bool
    {
        $teacher = $lesson['teacher'];
        if ($ctx['slots'][$slot]['number'] > $ctx['limit'][$lesson['classroom']]) {
            return false; // this section has no such period
        }
        if (isset($classBusy[$lesson['classroom']][$slot]) || isset($teacherBusy[$teacher][$slot]) || isset($ctx['unavailable'][$teacher][$slot])) {
            return false;
        }
        $day = $ctx['slots'][$slot]['day'];
        if (($subjectDay[$lesson['classroom']][$lesson['subject']][$day] ?? 0) >= $lesson['cap']) {
            return false;
        }
        if ($lesson['building'] !== null) {
            foreach ([-1, 1] as $step) {
                $neighbour = $ctx['slotOf'][$day.'-'.($ctx['slots'][$slot]['number'] + $step)] ?? null;
                $other = $neighbour === null ? null : ($teacherBuilding[$teacher][$neighbour] ?? null);
                if ($other !== null && $other !== $lesson['building']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * One-step repair: take a slot where the section is free but the teacher teaches another section, move that other lesson
     * to a different valid slot and put this lesson there.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $lesson
     * @param  array<string, array<string, mixed>>  $placements
     */
    private function repair(array $ctx, array $lesson, array &$classBusy, array &$teacherBusy, array &$teacherBuilding, array &$subjectDay, array &$teacherDay, array &$placements, \Closure $buildingOf): ?int
    {
        $teacher = $lesson['teacher'];
        $slots = array_keys($ctx['slots']);
        shuffle($slots);
        foreach ($slots as $slot) {
            $otherClass = $teacherBusy[$teacher][$slot] ?? null;
            if ($otherClass === null || isset($classBusy[$lesson['classroom']][$slot]) || isset($ctx['unavailable'][$teacher][$slot])) {
                continue;
            }
            $key = $otherClass.'@'.$slot;
            if (! isset($placements[$key]) || $placements[$key]['teacher'] !== $teacher) {
                continue; // a locked entry or someone else's
            }
            $moved = $placements[$key];
            $this->remove($ctx, $moved, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay, $teacherDay, $placements);

            $movedLesson = ['classroom' => $moved['classroom'], 'subject' => $moved['subject'], 'teacher' => $teacher, 'building' => $moved['building'], 'cap' => $moved['cap'], 'core' => $moved['core']];
            $target = null;
            $targetCount = PHP_INT_MAX;
            foreach (array_keys($ctx['slots']) as $y) {
                if ($y !== $slot && $this->valid($ctx, $movedLesson, $y, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay)) {
                    $count = $subjectDay[$moved['classroom']][$moved['subject']][$ctx['slots'][$y]['day']] ?? 0;
                    if ($count < $targetCount) { // keep the subject spread over the days
                        $target = $y;
                        $targetCount = $count;
                    }
                }
            }
            if ($target !== null && $this->valid($ctx, $lesson, $slot, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay)) {
                $this->place($ctx, $movedLesson, $target, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay, $teacherDay, $placements);

                return $slot;
            }
            $this->place($ctx, $movedLesson, $slot, $classBusy, $teacherBusy, $teacherBuilding, $subjectDay, $teacherDay, $placements); // put it back
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $lesson
     * @param  array<string, array<string, mixed>>  $placements
     */
    private function place(array $ctx, array $lesson, int $slot, array &$classBusy, array &$teacherBusy, array &$teacherBuilding, array &$subjectDay, array &$teacherDay, array &$placements): void
    {
        $day = $ctx['slots'][$slot]['day'];
        $classBusy[$lesson['classroom']][$slot] = true;
        $teacherBusy[$lesson['teacher']][$slot] = $lesson['classroom'];
        $teacherBuilding[$lesson['teacher']][$slot] = $lesson['building'];
        $subjectDay[$lesson['classroom']][$lesson['subject']][$day] = ($subjectDay[$lesson['classroom']][$lesson['subject']][$day] ?? 0) + 1;
        $teacherDay[$lesson['teacher']][$day] = ($teacherDay[$lesson['teacher']][$day] ?? 0) + 1;
        $placements[$lesson['classroom'].'@'.$slot] = $lesson + ['slot' => $slot];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $placement
     * @param  array<string, array<string, mixed>>  $placements
     */
    private function remove(array $ctx, array $placement, array &$classBusy, array &$teacherBusy, array &$teacherBuilding, array &$subjectDay, array &$teacherDay, array &$placements): void
    {
        $slot = $placement['slot'];
        $day = $ctx['slots'][$slot]['day'];
        unset($classBusy[$placement['classroom']][$slot], $teacherBusy[$placement['teacher']][$slot], $teacherBuilding[$placement['teacher']][$slot], $placements[$placement['classroom'].'@'.$slot]);
        $subjectDay[$placement['classroom']][$placement['subject']][$day]--;
        $teacherDay[$placement['teacher']][$day]--;
    }
}
