<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A teacher's plan for one subject in one section for a week: draft, then submitted to the admin, then approved or rejected. */
class LessonPlan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['teacher_id', 'classroom_id', 'subject_id', 'week_starts_on', 'title', 'objectives', 'content', 'status', 'reviewed_by', 'review_note', 'shared_with_parents'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['week_starts_on' => 'date:Y-m-d', 'shared_with_parents' => 'boolean'];
    }

    /** Only a draft or a rejected plan can be submitted. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
