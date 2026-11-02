<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use App\Modules\Academic\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A criterion written for one student only (their "special evaluation"). */
class BehaviorStudentCriterion extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['student_id', 'name', 'sort_order', 'is_active', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
