<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Communication\Models\Story;
use App\Shared\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Who may post a story to where, and who may read which story. */
class StoryAccess
{
    /** The current year's classrooms a person is linked to: the ones a teacher teaches or leads, the ones a student sits in, the ones a parent's children sit in. @return Collection<int, Classroom> */
    public function classroomsOf(User $user): Collection
    {
        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            return collect();
        }
        $rooms = Classroom::where('academic_year_id', $year->id);

        return match ($user->role) {
            Role::Teacher => $rooms->where(fn ($q) => $q->where('homeroom_teacher_id', $user->id)->orWhereIn('id', TeachingAssignment::where('teacher_id', $user->id)->select('classroom_id')))->get(),
            Role::Student => $rooms->whereIn('id', Enrollment::where('academic_year_id', $year->id)->where('student_id', $user->student?->id ?? 0)->select('classroom_id'))->get(),
            Role::Guardian => $rooms->whereIn('id', Enrollment::where('academic_year_id', $year->id)->whereIn('student_id', Student::whereHas('guardians', fn ($q) => $q->whereKey($user->id))->select('id'))->select('classroom_id'))->get(),
            Role::Admin => $rooms->get(),
        };
    }

    /** Where this person can post: the principal anywhere; a teacher only to the classes they teach or lead. @return Collection<int, Classroom> */
    public function postableClassrooms(User $user): Collection
    {
        return $user->hasRole(Role::Admin) || $user->hasRole(Role::Teacher) ? $this->classroomsOf($user) : collect();
    }

    public function canPost(User $user): bool
    {
        return $user->hasRole(Role::Admin) || $user->hasRole(Role::Teacher);
    }

    /** The stories a person may read. @return Builder<Story> */
    public function visibleTo(User $user): Builder
    {
        if ($user->hasRole(Role::Admin)) {
            return Story::query();
        }
        $rooms = $this->classroomsOf($user);
        $grades = $rooms->pluck('grade_level_id')->unique();

        return Story::query()->where(fn ($q) => $q->where('audience', 'school')
            ->orWhere(fn ($w) => $w->where('audience', 'classroom')->whereIn('classroom_id', $rooms->pluck('id')))
            ->orWhere(fn ($w) => $w->where('audience', 'grade')->whereIn('grade_level_id', $grades))
            ->orWhere('author_id', $user->id));
    }

    public function canView(User $user, Story $story): bool
    {
        return $this->visibleTo($user)->whereKey($story->id)->exists();
    }

    /** The author and the principal may delete, pin and close the comments of a story. */
    public function canManage(User $user, Story $story): bool
    {
        return $user->hasRole(Role::Admin) || $story->author_id === $user->id;
    }
}
