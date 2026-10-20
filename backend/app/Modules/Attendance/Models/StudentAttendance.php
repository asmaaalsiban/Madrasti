<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\AttendanceStatus;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One student, one school day. */
class StudentAttendance extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['student_id', 'classroom_id', 'date', 'status', 'note', 'marked_by'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'status' => AttendanceStatus::class];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AbsenceNotification::class);
    }
}
