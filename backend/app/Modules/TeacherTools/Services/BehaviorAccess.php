<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Services;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\Role;
use Illuminate\Support\Collection;

/** Who may evaluate whom: an admin anyone enrolled this year, a teacher the students of the sections they teach or are homeroom of. */
class BehaviorAccess
{
    /** @return Collection<int, Classroom> */
    public function classrooms(User $user): Collection
    {
        return Classroom::whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->when(! $user->hasRole(Role::Admin), fn ($q) => $q->where(fn ($w) => $w
                ->where('homeroom_teacher_id', $user->id)
                ->orWhereIn('id', TeachingAssignment::where('teacher_id', $user->id)->select('classroom_id'))))
            ->orderBy('name')->get();
    }

    public function canEvaluate(User $user, Student $student): bool
    {
        $ids = $this->classrooms($user)->pluck('id');

        return $ids->isNotEmpty() && $student->enrollments()->whereIn('classroom_id', $ids)->exists();
    }
}
