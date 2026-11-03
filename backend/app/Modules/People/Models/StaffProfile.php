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
        'timetable', 'attendance', 'attendance_report', 'exams', 'lesson_plans', 'daily_summary', 'homework', 'grades',
        'behavior', 'motivation', 'surveys', 'signature', 'lesson_plan_review', 'department',
        'duties', 'staff_attendance', 'payslips', 'procurement',
        'finance', 'renewals', 'payroll',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'staff_type', 'subject_id', 'floor', 'grade_from_id', 'grade_to_id', 'teaches_gender', 'job_title', 'hired_on', 'weekly_hours', 'does_duty', 'hidden_features'];

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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
