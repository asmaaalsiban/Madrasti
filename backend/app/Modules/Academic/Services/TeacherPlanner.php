<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevelSubject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** What has to be taught this year: every classroom of the current year with the subjects of its grade's curriculum. */
class TeacherPlanner
{
    public const DEFAULT_MAX_LOAD = 24;

    /** Periods a teacher teaches a week at most: the school's setting, otherwise 24. */
    public function maxLoad(): int
    {
        $setting = DB::table('school_settings')->where('key', 'max_teacher_load')->value('value');

        return $setting !== null ? (int) json_decode($setting, true) : self::DEFAULT_MAX_LOAD;
    }

    /**
     * @return Collection<int, array{classroom_id: int, classroom: string, grade_order: int, gender: string, subject_id: int, periods: int, building_id: ?int}>
     */
    public function demands(): Collection
    {
        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            return collect();
        }

        $curriculum = GradeLevelSubject::get()->groupBy('grade_level_id');

        return Classroom::where('academic_year_id', $year->id)->with(['room.floor', 'gradeLevel'])->get()
            ->flatMap(fn (Classroom $classroom) => $curriculum->get($classroom->grade_level_id, collect())->map(fn (GradeLevelSubject $row) => [
                'classroom_id' => $classroom->id,
                'classroom' => $classroom->name,
                'grade_order' => (int) $classroom->gradeLevel->sort_order,
                'gender' => $classroom->gender ?? 'mixed',
                'subject_id' => $row->subject_id,
                'periods' => $row->weekly_periods,
                'building_id' => $classroom->room?->floor?->building_id,
            ]))->values();
    }

    /**
     * Teachers a subject needs: every building has its own teachers, so each building's periods are divided by the load.
     *
     * @param  Collection<int, array<string, mixed>>  $demands
     */
    public function teachersNeeded(Collection $demands, int $maxLoad): int
    {
        return (int) $demands->groupBy('building_id')->sum(fn (Collection $rows) => (int) ceil($rows->sum('periods') / $maxLoad));
    }
}
