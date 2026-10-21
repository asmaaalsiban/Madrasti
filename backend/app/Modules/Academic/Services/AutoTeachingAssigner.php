<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use App\Models\User;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives every subject of every classroom (current year, from the curriculum) a teacher, where it has none yet.
 *
 * Rules: the teacher teaches the subject, is active, and has room left in their weekly load; a teacher stays in one
 * building (the one of their first classroom), so nobody walks between buildings in the five minutes between two periods;
 * teachers are filled one after another (a teacher takes as many sections as fit) so as few teachers as possible are used.
 * Existing assignments are kept and counted.
 */
class AutoTeachingAssigner
{
    public function __construct(private readonly TeacherPlanner $planner) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $maxLoad, bool $dryRun): array
    {
        $demands = $this->planner->demands();
        $taken = TeachingAssignment::whereIn('classroom_id', $demands->pluck('classroom_id')->unique())->get();
        $takenKeys = $taken->mapWithKeys(fn ($a) => [$a->classroom_id.'-'.$a->subject_id => true]);
        $buildingOf = $demands->pluck('building_id', 'classroom_id');

        $load = [];
        $home = [];
        foreach ($taken as $a) {
            $load[$a->teacher_id] = ($load[$a->teacher_id] ?? 0) + $a->weekly_periods;
            $building = $buildingOf[$a->classroom_id] ?? null;
            if ($building !== null) {
                $home[$a->teacher_id] ??= $building;
            }
        }

        $teachers = User::query()->where('is_active', true)
            ->whereHas('staffProfile', fn ($q) => $q->whereNotNull('subject_id')->whereIn('staff_type', StaffType::valuesIn('teaching')))
            ->with('staffProfile')->orderBy('id')->get()->groupBy(fn (User $u) => $u->staffProfile->subject_id);
        $subjects = Subject::all()->keyBy('id');

        $pending = $demands->reject(fn ($d) => $takenKeys->has($d['classroom_id'].'-'.$d['subject_id']))
            ->sortBy(fn ($d) => sprintf('%s|%05d|%s|%05d', $d['building_id'] ?? 'zzzz', $d['grade_order'], $d['classroom'], $d['subject_id']))->values();

        $assigned = [];
        $unassigned = [];
        foreach ($pending as $d) {
            $teacher = $this->pick($teachers->get($d['subject_id'], collect()), $load, $home, $d['building_id'], $d['periods'], $maxLoad);
            if ($teacher === null) {
                $unassigned[] = $d;

                continue;
            }
            $load[$teacher->id] = ($load[$teacher->id] ?? 0) + $d['periods'];
            if ($d['building_id'] !== null) {
                $home[$teacher->id] ??= $d['building_id'];
            }
            $assigned[] = $d + ['teacher_id' => $teacher->id];
        }

        if (! $dryRun) {
            DB::transaction(fn () => collect($assigned)->each(fn ($a) => TeachingAssignment::create([
                'classroom_id' => $a['classroom_id'], 'subject_id' => $a['subject_id'], 'teacher_id' => $a['teacher_id'], 'weekly_periods' => $a['periods'],
            ])));
        }

        return [
            'dry_run' => $dryRun,
            'max_load' => $maxLoad,
            'assigned' => count($assigned),
            'teachers' => $this->teacherSummary(collect($assigned), $teachers->flatten(1)->keyBy('id'), $subjects, $load),
            'unassigned' => collect($unassigned)->map(fn ($d) => [
                'classroom' => $d['classroom'], 'subject' => $subjects[$d['subject_id']]->name_en, 'subject_ar' => $subjects[$d['subject_id']]->name_ar,
            ])->values()->take(200)->all(),
            'shortages' => $this->shortages(collect($unassigned), $teachers, $subjects, $maxLoad),
        ];
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @param  array<int, int>  $load
     * @param  array<int, int>  $home
     */
    private function pick(Collection $candidates, array $load, array $home, ?int $building, int $periods, int $maxLoad): ?User
    {
        $fits = $candidates->filter(fn (User $t) => ($load[$t->id] ?? 0) + $periods <= $maxLoad
            && ($building === null || ! isset($home[$t->id]) || $home[$t->id] === $building));

        // The most loaded teacher of this building that still fits; otherwise the first free one (nobody has a building yet).
        $sameBuilding = $fits->filter(fn (User $t) => $building !== null && ($home[$t->id] ?? null) === $building)->sortByDesc(fn (User $t) => $load[$t->id] ?? 0);

        return $sameBuilding->first() ?? $fits->filter(fn (User $t) => ! isset($home[$t->id]))->sortByDesc(fn (User $t) => $load[$t->id] ?? 0)->first() ?? $fits->first();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $assigned
     * @param  Collection<int, User>  $teachers
     * @param  Collection<int, Subject>  $subjects
     * @param  array<int, int>  $load
     * @return list<array<string, mixed>>
     */
    private function teacherSummary(Collection $assigned, Collection $teachers, Collection $subjects, array $load): array
    {
        return $assigned->groupBy('teacher_id')->map(fn (Collection $rows, $id) => [
            'id' => (int) $id,
            'name' => $teachers[$id]->name,
            'subject' => $subjects[$rows->first()['subject_id']]->name_en,
            'subject_ar' => $subjects[$rows->first()['subject_id']]->name_ar,
            'classrooms' => $rows->count(),
            'load' => $load[$id] ?? 0,
        ])->sortBy('subject')->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $unassigned
     * @param  Collection<int|string, Collection<int, User>>  $teachers
     * @param  Collection<int, Subject>  $subjects
     * @return list<array<string, mixed>>
     */
    private function shortages(Collection $unassigned, Collection $teachers, Collection $subjects, int $maxLoad): array
    {
        return $unassigned->groupBy('subject_id')->map(fn (Collection $rows, $subjectId) => [
            'subject' => $subjects[$subjectId]->name_en,
            'subject_ar' => $subjects[$subjectId]->name_ar,
            'periods' => (int) $rows->sum('periods'),
            'teachers_needed' => $this->planner->teachersNeeded($rows, $maxLoad),
            'teachers_available' => $teachers->get($subjectId, collect())->count(),
            'teachers_missing' => $this->planner->teachersNeeded($rows, $maxLoad),
        ])->sortBy('subject')->values()->all();
    }
}
