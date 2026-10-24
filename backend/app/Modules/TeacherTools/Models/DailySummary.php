<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a teacher covered in one subject in one section on one day; students and parents read it once published. */
class DailySummary extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['classroom_id', 'subject_id', 'teacher_id', 'date', 'summary', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'published_at' => 'datetime'];
    }

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
