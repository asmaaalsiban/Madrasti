<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member of staff on duty at a post on a date. A locked one (set by hand) survives regeneration. */
class DutyAssignment extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'duty_post_id', 'schedule_generation_id', 'date', 'starts_at', 'ends_at', 'location', 'is_locked'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'is_locked' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(DutyPost::class, 'duty_post_id');
    }
}
