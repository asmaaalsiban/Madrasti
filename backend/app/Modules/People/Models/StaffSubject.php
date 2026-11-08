<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use App\Modules\Academic\Models\GradeLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One more subject a teacher teaches, for its own grades (no grades = every grade the teacher teaches). */
class StaffSubject extends Model
{
    /** @var list<string> */
    protected $fillable = ['staff_profile_id', 'subject_id', 'grade_from_id', 'grade_to_id'];

    public function gradeFrom(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_from_id');
    }

    public function gradeTo(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_to_id');
    }
}
