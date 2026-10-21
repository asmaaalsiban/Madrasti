<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Room;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One lesson: a section has a subject with a teacher in a period. A locked entry survives regeneration. */
class TimetableEntry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['academic_year_id', 'classroom_id', 'period_id', 'subject_id', 'teacher_id', 'room_id', 'schedule_generation_id', 'is_locked'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_locked' => 'boolean'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
