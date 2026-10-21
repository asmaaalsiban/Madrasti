<?php

declare(strict_types=1);

namespace App\Modules\Grading\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\Term;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A quiz, exam, assignment... of one subject in one classroom and term. Students see it only once published. */
class Assessment extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['classroom_id', 'subject_id', 'term_id', 'teacher_id', 'title', 'type', 'max_score', 'weight', 'held_on', 'is_published'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['max_score' => 'float', 'weight' => 'float', 'held_on' => 'date:Y-m-d', 'is_published' => 'boolean'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }
}
