<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use App\Models\User;
use App\Modules\Academic\Models\Subject;
use App\Shared\Enums\StaffType;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff details of a user: what kind of employee they are and, for teaching staff, the subject.
 */
class StaffProfile extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'staff_type', 'subject_id', 'floor', 'job_title', 'hired_on', 'weekly_hours'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['staff_type' => StaffType::class, 'hired_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
