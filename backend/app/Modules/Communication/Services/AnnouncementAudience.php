<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\Role;
use Illuminate\Support\Collection;

/** The sections of the current year a user belongs to: a student's own, a parent's children's, a teacher's (they teach or are homeroom of). */
class AnnouncementAudience
{
    /** @return Collection<int, int> */
    public function classroomIds(User $user): Collection
    {
        $current = Classroom::whereHas('academicYear', fn ($q) => $q->where('is_current', true))->select('id');

        return match ($user->role) {
            Role::Student => Enrollment::whereIn('classroom_id', $current)->whereIn('student_id', $user->student ? [$user->student->id] : [])->pluck('classroom_id'),
            Role::Guardian => Enrollment::whereIn('classroom_id', $current)->whereIn('student_id', $user->children()->select('students.id'))->pluck('classroom_id'),
            Role::Teacher => Classroom::whereIn('id', $current)->where(fn ($q) => $q
                ->where('homeroom_teacher_id', $user->id)
                ->orWhereIn('id', TeachingAssignment::where('teacher_id', $user->id)->select('classroom_id')))->pluck('id'),
            Role::Admin => Classroom::whereIn('id', $current)->pluck('id'),
        };
    }
}
