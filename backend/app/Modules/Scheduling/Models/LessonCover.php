<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A teacher who covers one lesson of an absent teacher on one date. */
class LessonCover extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['date', 'period_id', 'classroom_id', 'subject_id', 'absent_teacher_id', 'cover_teacher_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
