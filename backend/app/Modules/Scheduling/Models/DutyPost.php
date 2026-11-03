<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A supervision post (the gate, the yard at break...) with its time window, how many people it needs and its days. */
class DutyPost extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'starts_at', 'ends_at', 'staff_count', 'days', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['days' => 'array', 'is_active' => 'boolean'];
    }
}
