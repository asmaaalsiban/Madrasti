<?php

declare(strict_types=1);

namespace App\Modules\Communication\Services;

use App\Modules\Academic\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Parents' consent to publish photos of their child. The school chooses how strict to be: `required` (a photo can only be posted to a
 * group where every child's parents said yes), `warn` (the teacher is told who has not said yes), or `off`.
 */
class PhotoConsent
{
    public const MODES = ['required', 'warn', 'off'];

    public function mode(): string
    {
        $mode = DB::table('school_settings')->where('key', 'photo_consent_mode')->value('value');

        return in_array($mode, self::MODES, true) ? $mode : 'warn';
    }

    public function setMode(string $mode): void
    {
        DB::table('school_settings')->updateOrInsert(['key' => 'photo_consent_mode'], ['value' => $mode, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** The students a post for this audience reaches (this year's enrolments). @return Collection<int, Student> */
    public function audience(string $audience, ?int $classroomId, ?int $gradeLevelId): Collection
    {
        $rooms = DB::table('classrooms')->join('academic_years', 'academic_years.id', '=', 'classrooms.academic_year_id')->where('academic_years.is_current', true)
            ->when($audience === 'classroom', fn ($q) => $q->where('classrooms.id', $classroomId))
            ->when($audience === 'grade', fn ($q) => $q->where('classrooms.grade_level_id', $gradeLevelId))->pluck('classrooms.id');

        return Student::whereIn('id', DB::table('enrollments')->whereIn('classroom_id', $rooms)->select('student_id'))->orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'photo_consent']);
    }

    /** The students of the audience whose parents did not say yes (no answer, or no). @return Collection<int, Student> */
    public function missing(string $audience, ?int $classroomId, ?int $gradeLevelId): Collection
    {
        return $this->audience($audience, $classroomId, $gradeLevelId)->filter(fn ($s) => $s->photo_consent !== true)->values();
    }

    public function set(Student $student, bool $allowed, int $byUserId): void
    {
        $student->forceFill(['photo_consent' => $allowed, 'photo_consent_at' => now(), 'photo_consent_by' => $byUserId])->save();
    }
}
