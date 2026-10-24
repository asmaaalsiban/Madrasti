<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use App\Modules\Academic\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A student's answer to a homework: a text and/or a photo. */
class HomeworkSubmission extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['homework_assignment_id', 'student_id', 'body', 'submitted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function homework(): BelongsTo
    {
        return $this->belongsTo(HomeworkAssignment::class, 'homework_assignment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(HomeworkFile::class)->orderBy('position')->orderBy('id');
    }
}
