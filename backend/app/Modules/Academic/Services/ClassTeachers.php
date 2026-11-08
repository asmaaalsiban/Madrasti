<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Shared\Enums\StaffType;
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

    /** The class teacher of a section: its homeroom teacher, when they are active teaching staff. (No separate flag is needed.) */
    public function classTeacherOf(int $classroomId): ?int
    {
        $types = StaffType::valuesIn('teaching');
        $row = DB::table('classrooms')->join('staff_profiles', 'staff_profiles.user_id', '=', 'classrooms.homeroom_teacher_id')->join('users', 'users.id', '=', 'classrooms.homeroom_teacher_id')
            ->where('classrooms.id', $classroomId)->where('users.is_active', true)->whereIn('staff_profiles.staff_type', $types)->first(['classrooms.homeroom_teacher_id']);

        return $row?->homeroom_teacher_id !== null ? (int) $row->homeroom_teacher_id : null;
    }

    /** Why a section has no class teacher: 'no_homeroom' (nobody chosen) or 'not_teacher' (the homeroom teacher is inactive or not teaching staff). */
    public function whyNot(int $classroomId): string
    {
        return DB::table('classrooms')->where('id', $classroomId)->value('homeroom_teacher_id') === null ? 'no_homeroom' : 'not_teacher';
    }
}
