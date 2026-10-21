<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** One cell of the curriculum: a subject of a grade with its periods per week. */
class GradeLevelSubject extends Model
{
    use Auditable;

    protected $table = 'grade_level_subjects';

    /**
     * @var list<string>
     */
    protected $fillable = ['grade_level_id', 'subject_id', 'weekly_periods'];
}
