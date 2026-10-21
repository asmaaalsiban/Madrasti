<?php

declare(strict_types=1);

namespace App\Modules\Grading\Models;

use App\Modules\Academic\Models\Student;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One student's result in one assessment (null score = not graded, e.g. absent). */
class AssessmentScore extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['assessment_id', 'student_id', 'score', 'first_score', 'status', 'remark'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['score' => 'float', 'first_score' => 'float'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
