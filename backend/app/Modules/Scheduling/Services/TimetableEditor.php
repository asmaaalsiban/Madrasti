<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Places one lesson by hand with the same hard rules the generator keeps: the teacher comes from the assignment of the
 * section, is free and available in the period, is not in another building the period before or after, and the section
 * has that period. A hand-made lesson is locked, so regenerating keeps it.
 */
class TimetableEditor
{
    public function place(AcademicYear $year, Classroom $classroom, Period $period, int $subjectId, int $by): TimetableEntry
    {
        $assignment = TeachingAssignment::where('classroom_id', $classroom->id)->where('subject_id', $subjectId)->first();
        if ($assignment === null) {
            $this->fail('subject_id', 'scheduling.no_teacher_for_subject');
        }
        $limit = $classroom->gradeLevel?->periods_per_day;
        if ($limit !== null && $period->number > $limit) {
            $this->fail('period_id', 'scheduling.period_beyond_grade', ['limit' => $limit]);
        }
        $teacher = $assignment->teacher_id;

        if (DB::table('teacher_unavailabilities')->where('teacher_id', $teacher)->where('period_id', $period->id)->exists()) {
            $this->fail('period_id', 'scheduling.teacher_unavailable');
        }
        $busy = TimetableEntry::where('teacher_id', $teacher)->where('period_id', $period->id)->where('classroom_id', '!=', $classroom->id)->with('classroom')->first();
        if ($busy !== null) {
            $this->fail('period_id', 'scheduling.teacher_busy', ['classroom' => $busy->classroom->name]);
        }

        $building = $classroom->loadMissing('room.floor')->room?->floor?->building_id;
        if ($building !== null) {
            $neighbours = Period::where('day_of_week', $period->day_of_week)->whereIn('number', [$period->number - 1, $period->number + 1])->pluck('id');
            $others = TimetableEntry::where('teacher_id', $teacher)->whereIn('period_id', $neighbours)->where('classroom_id', '!=', $classroom->id)->with('classroom.room.floor')->get();
            foreach ($others as $other) {
                $otherBuilding = $other->classroom->room?->floor?->building_id;
                if ($otherBuilding !== null && $otherBuilding !== $building) {
                    $this->fail('period_id', 'scheduling.teacher_other_building', ['classroom' => $other->classroom->name]);
                }
            }
        }

        return DB::transaction(function () use ($year, $classroom, $period, $subjectId, $teacher): TimetableEntry {
            TimetableEntry::where('classroom_id', $classroom->id)->where('period_id', $period->id)->delete();

            return TimetableEntry::create([
                'academic_year_id' => $year->id, 'classroom_id' => $classroom->id, 'period_id' => $period->id, 'subject_id' => $subjectId,
                'teacher_id' => $teacher, 'room_id' => $classroom->room_id, 'is_locked' => true,
            ]);
        });
    }

    /** @param  array<string, mixed>  $replace */
    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
