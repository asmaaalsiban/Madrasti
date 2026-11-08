<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Shared\Enums\StaffType;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Staff details of a user: what kind of employee they are and, for teaching staff, the subject.
 */
class StaffProfile extends Model
{
    use Auditable;

    /** The screens of a member of staff that the principal can switch off (the same keys the app uses). */
    public const FEATURES = [
        'chat', 'announcements', 'stories', 'tasks',
        'timetable', 'attendance', 'attendance_report', 'exams', 'lesson_plans', 'daily_summary', 'homework', 'question_bank', 'grades',
        'behavior', 'motivation', 'surveys', 'signature', 'lesson_plan_review', 'department',
        'duties', 'staff_attendance', 'payslips', 'procurement',
        'finance', 'renewals', 'payroll',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'staff_type', 'subject_id', 'floor', 'grade_from_id', 'grade_to_id', 'teaches_gender', 'job_title', 'hired_on', 'weekly_hours', 'does_duty', 'hidden_features', 'degree', 'specialization', 'university', 'graduation_year', 'experience_years', 'courses'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['staff_type' => StaffType::class, 'hired_on' => 'date', 'does_duty' => 'boolean', 'hidden_features' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gradeFrom(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_from_id');
    }

    public function gradeTo(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_to_id');
    }

    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'staff_classrooms')->withTimestamps();
    }

    /**
     * Whether this teacher may teach a section: its grade is inside their grades and its students are the ones they teach
     * (a mixed section needs a teacher who teaches both).
     */
    public function canTeach(int $gradeOrder, ?string $classroomGender, ?int $classroomId = null): bool
    {
        // Sections chosen by the admin limit the teacher to those sections.
        if ($classroomId !== null && $this->classrooms->isNotEmpty() && ! $this->classrooms->contains('id', $classroomId)) {
            return false;
        }

        $inGrades = $this->gradeFrom === null || $this->gradeTo === null
            || ($this->gradeFrom->sort_order <= $gradeOrder && $gradeOrder <= $this->gradeTo->sort_order);

        return $inGrades && ($this->teaches_gender === null || $this->teaches_gender === $classroomGender);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<StaffSubject, $this> */
    public function extraSubjects(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StaffSubject::class);
    }

    /** Every subject the teacher teaches: the main one and the extra ones. @return list<int> */
    public function subjectIds(): array
    {
        return array_values(array_unique(array_filter([$this->subject_id, ...$this->extraSubjects->pluck('subject_id')->all()])));
    }

    /**
     * Whether this teacher may teach THIS subject in a section: the main subject follows the teacher's grades, an extra subject
     * follows its own grades. The sections chosen for the teacher and the students they teach (boys / girls) apply to all.
     */
    public function canTeachSubject(int $subjectId, int $gradeOrder, ?string $classroomGender, ?int $classroomId = null, ?int $gradeId = null): bool
    {
        if ($subjectId === $this->subject_id) {
            return $this->canTeach($gradeOrder, $classroomGender, $classroomId);
        }
        $extra = $this->extraSubjects->firstWhere('subject_id', $subjectId);
        if ($extra === null) {
            return false;
        }
        if ($classroomId !== null && $this->classrooms->isNotEmpty() && ! $this->classrooms->contains('id', $classroomId)) {
            return false;
        }
        // The grades picked for the extra subject; none picked = the grades of the teacher.
        $picked = $extra->grade_ids ?? [];
        if ($picked !== []) {
            $gradeId ??= \App\Modules\Academic\Models\GradeLevel::where('sort_order', $gradeOrder)->value('id');
            $inGrades = in_array((int) $gradeId, array_map('intval', $picked), true);
        } else {
            $from = $this->gradeFrom;
            $to = $this->gradeTo;
            $inGrades = $from === null || $to === null || ($from->sort_order <= $gradeOrder && $gradeOrder <= $to->sort_order);
        }

        return $inGrades && ($this->teaches_gender === null || $this->teaches_gender === $classroomGender);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
