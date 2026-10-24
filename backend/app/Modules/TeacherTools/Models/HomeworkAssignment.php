<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Homework a teacher sends to one section for one subject; students and their parents see it once published. */
class HomeworkAssignment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['classroom_id', 'subject_id', 'teacher_id', 'title', 'description', 'due_at', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'published_at' => 'datetime'];
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
