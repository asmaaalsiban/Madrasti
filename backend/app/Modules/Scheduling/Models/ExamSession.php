<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\Term;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One exam: a subject for one section of a term, on a date and a time, with the teachers who watch the room. */
class ExamSession extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['term_id', 'classroom_id', 'subject_id', 'room_id', 'schedule_generation_id', 'exam_date', 'starts_at', 'ends_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['exam_date' => 'date:Y-m-d'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function invigilators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'exam_invigilators', 'exam_session_id', 'user_id')->withTimestamps();
    }
}
