<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's re-registration for the next academic year: pending, renewed (placed in the next grade), declined, or graduating (last grade). */
class StudentRenewal extends Model
{
    use Auditable;

    public const STATUSES = ['pending', 'renewed', 'declined', 'graduating'];

    /**
     * @var list<string>
     */
    protected $fillable = ['student_id', 'from_year_id', 'to_year_id', 'from_classroom_id', 'from_grade_level_id', 'to_grade_level_id', 'to_classroom_id', 'status', 'decline_reason', 'decided_by', 'decided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'from_classroom_id');
    }

    public function fromGrade(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'from_grade_level_id');
    }

    public function toGrade(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'to_grade_level_id');
    }

    public function toClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'to_classroom_id');
    }

    public function toYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'to_year_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
