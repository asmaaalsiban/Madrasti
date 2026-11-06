<?php

declare(strict_types=1);

namespace App\Modules\QuestionBank\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;

/** Who may write questions for which grade and subject, and which grade a student belongs to. */
class BankAccess
{
    /**
     * The (grade, subject) pairs a user may write for: a teacher the ones they teach, the admin every one of the curriculum.
     *
     * @return list<array{grade_level_id: int, subject_id: int}>
     */
    public function pairs(User $user): array
    {
        $rows = $user->hasRole(Role::Admin)
            ? DB::table('grade_level_subjects')->select('grade_level_id', 'subject_id')->get()
            : DB::table('classroom_subject_teacher as t')->join('classrooms as c', 'c.id', '=', 't.classroom_id')->where('t.teacher_id', $user->id)->select('c.grade_level_id', 't.subject_id')->distinct()->get();

        return $rows->map(fn ($r) => ['grade_level_id' => (int) $r->grade_level_id, 'subject_id' => (int) $r->subject_id])->unique(fn ($p) => $p['grade_level_id'].'-'.$p['subject_id'])->values()->all();
    }

    public function can(User $user, int $gradeLevelId, int $subjectId): bool
    {
        foreach ($this->pairs($user) as $p) {
            if ($p['grade_level_id'] === $gradeLevelId && $p['subject_id'] === $subjectId) {
                return true;
            }
        }

        return false;
    }

    /** The grade of a student this year (from their section), or null. */
    public function gradeOf(Student $student): ?int
    {
        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            return null;
        }
        $id = DB::table('enrollments as e')->join('classrooms as c', 'c.id', '=', 'e.classroom_id')->where('e.student_id', $student->id)->where('e.academic_year_id', $year->id)->value('c.grade_level_id');

        return $id === null ? null : (int) $id;
    }
}
