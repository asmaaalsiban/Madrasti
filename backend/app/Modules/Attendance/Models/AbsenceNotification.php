<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsenceNotification extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['student_attendance_id', 'guardian_id', 'phone', 'channel', 'status', 'error', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(StudentAttendance::class, 'student_attendance_id');
    }
}
