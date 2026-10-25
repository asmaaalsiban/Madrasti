<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Communication\Models\Conversation;
use App\Shared\Enums\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may chat with whom. The admin with anyone. A teacher with the students of the sections they teach (or are homeroom of)
 * and with those students' parents; students and parents with the teachers of their section. Students never write to each
 * other or to parents. A section group holds that section's students (and parents if the group says so) and teachers.
 */
class ChatAccess
{
    public function __construct(private readonly AnnouncementAudience $audience) {}

    /** The sections (current year) a user is part of: a teacher's, a student's own, a parent's children's. */
    public function sections(User $user): Collection
    {
        return $this->audience->classroomIds($user);
    }

    public function canMessage(User $from, User $to): bool
    {
        if ($from->id === $to->id || ! $from->is_active || ! $to->is_active) {
            return false;
        }
        if ($from->hasRole(Role::Admin) || $to->hasRole(Role::Admin)) {
            return true;
        }
        $pair = [$from->role, $to->role];
        $teacherWithFamily = in_array(Role::Teacher, $pair, true) && (in_array(Role::Student, $pair, true) || in_array(Role::Guardian, $pair, true));

        return $teacherWithFamily && $this->sections($from)->intersect($this->sections($to))->isNotEmpty();
    }

    /**
     * The people a user can start a chat with.
     *
     * @return Collection<int, User>
     */
    public function contacts(User $me, ?string $search = null): Collection
    {
        $query = User::where('is_active', true)->whereKeyNot($me->id);
        if (filled($search)) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($search)).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }
        if ($me->hasRole(Role::Admin)) {
            return $query->orderBy('name')->limit(100)->get();
        }

        $sections = $this->sections($me);
        $ids = collect($this->adminIds());
        if ($me->hasRole(Role::Teacher)) {
            $ids = $ids->merge($this->studentUserIds($sections))->merge($this->parentIds($sections));
        } else {
            $ids = $ids->merge($this->teacherIds($sections));
        }

        return $query->whereIn('id', $ids->unique())->orderBy('name')->limit(100)->get();
    }

    /**
     * Everyone who belongs in a section group: the section's students (and their parents when the group includes them),
     * its teachers and the creator.
     *
     * @return Collection<int, int>
     */
    public function groupMembers(Conversation $group): Collection
    {
        $sections = collect([$group->classroom_id]);
        $ids = collect($this->studentUserIds($sections))->merge($this->teacherIds($sections))->push($group->created_by);
        if ($group->include_parents) {
            $ids = $ids->merge($this->parentIds($sections));
        }

        return $ids->unique()->values();
    }

    /** Makes the group's participants match the section today (new students join, students who left go). */
    public function syncGroup(Conversation $group): void
    {
        if ($group->type !== 'group' || $group->classroom_id === null) {
            return;
        }
        $group->participants()->sync($this->groupMembers($group)->all());
    }

    /** @return list<int> */
    private function adminIds(): array
    {
        return User::where('role', Role::Admin->value)->where('is_active', true)->pluck('id')->all();
    }

    /** @return Collection<int, int> */
    private function teacherIds(Collection $sections): Collection
    {
        return Classroom::whereIn('id', $sections)->whereNotNull('homeroom_teacher_id')->pluck('homeroom_teacher_id')
            ->merge(TeachingAssignment::whereIn('classroom_id', $sections)->pluck('teacher_id'))->unique()->values();
    }

    /** @return Collection<int, int> */
    private function studentUserIds(Collection $sections): Collection
    {
        return Student::whereIn('id', $this->enrolled($sections))->whereNotNull('user_id')->pluck('user_id');
    }

    /** @return Collection<int, int> */
    private function parentIds(Collection $sections): Collection
    {
        return DB::table('guardian_student')->whereIn('student_id', $this->enrolled($sections))->pluck('guardian_id')->unique()->values();
    }

    /** @return Collection<int, int> */
    private function enrolled(Collection $sections): Collection
    {
        $year = AcademicYear::where('is_current', true)->first();

        return Enrollment::whereIn('classroom_id', $sections)->where('academic_year_id', $year?->id)->pluck('student_id');
    }
}
