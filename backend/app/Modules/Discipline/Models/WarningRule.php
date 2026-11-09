<?php

declare(strict_types=1);

namespace App\Modules\Discipline\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** "If [metric] reaches [threshold] within [window_days] days, raise a warning of this level." */
class WarningRule extends Model
{
    use Auditable;

    public const METRICS = ['student' => ['absent_days', 'late_count', 'negative_notes'], 'staff' => ['absent_days', 'late_count']];

    /** @var list<string> */
    protected $fillable = ['subject_type', 'name', 'metric', 'threshold', 'window_days', 'category', 'level', 'title', 'body', 'auto_send', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['auto_send' => 'boolean', 'is_active' => 'boolean', 'threshold' => 'integer', 'window_days' => 'integer', 'level' => 'integer'];
    }
}
