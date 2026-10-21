<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who teaches which subject in which classroom (one teacher per subject per classroom). */
class TeachingAssignment extends Model
{
    use Auditable;

    protected $table = 'classroom_subject_teacher';

    /**
     * @var list<string>
     */
    protected $fillable = ['classroom_id', 'subject_id', 'teacher_id', 'weekly_periods'];

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
