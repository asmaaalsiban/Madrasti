<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use Illuminate\Support\Facades\DB;

/**
 * In a class-teacher grade (kindergarten, grades 1-3) the homeroom teacher of a section teaches every subject of the
 * curriculum except the ones kept for specialists (English, computer...). This says which is which.
 */
class ClassTeachers
{
    /** Whether the subject of this grade is taught by the section's class teacher. */
    public function isClassTeacherSubject(int $gradeLevelId, int $subjectId): bool
    {
        return isset($this->map()[$gradeLevelId.'-'.$subjectId]);
    }

    /** @return array<string, true> "grade-subject" of every subject the class teacher teaches */
    public function map(): array
    {
        static $cache = null;
        if ($cache === null || app()->runningUnitTests()) {
            $grades = GradeLevel::where('class_teacher_grade', true)->pluck('id');
            $cache = GradeLevelSubject::whereIn('grade_level_id', $grades)->where('specialist', false)->get(['grade_level_id', 'subject_id'])
                ->mapWithKeys(fn ($r) => [$r->grade_level_id.'-'.$r->subject_id => true])->all();
        }

        return $cache;
    }

    /** The homeroom teacher of a section, when they are a class teacher. */
    public function classTeacherOf(int $classroomId): ?int
    {
        $row = DB::table('classrooms')->join('staff_profiles', 'staff_profiles.user_id', '=', 'classrooms.homeroom_teacher_id')->where('classrooms.id', $classroomId)->where('staff_profiles.is_class_teacher', true)->first(['classrooms.homeroom_teacher_id']);

        return $row?->homeroom_teacher_id !== null ? (int) $row->homeroom_teacher_id : null;
    }
}
