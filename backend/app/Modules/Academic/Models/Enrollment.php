<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student placed in a classroom for one academic year (a student has at most one per year).
 */
class Enrollment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['student_id', 'classroom_id', 'academic_year_id'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
