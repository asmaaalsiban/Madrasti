<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A teacher's evaluation of a student for one week: a score per criterion and a note. */
class BehaviorEvaluation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['student_id', 'teacher_id', 'week_starts_on', 'evaluated_on', 'note', 'visible_to_parent'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['week_starts_on' => 'date:Y-m-d', 'evaluated_on' => 'date:Y-m-d', 'visible_to_parent' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(BehaviorEvaluationScore::class);
    }
}
