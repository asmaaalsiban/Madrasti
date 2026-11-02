<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One staff member's day: when they arrived and left, and whether they were on time, late, absent or on leave. */
class StaffAttendance extends Model
{
    use Auditable;

    public const STATUSES = ['present', 'late', 'absent', 'leave'];

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'date', 'check_in', 'check_out', 'worked_minutes', 'status', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
