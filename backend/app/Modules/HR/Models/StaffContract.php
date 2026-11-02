<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A member of staff's contract (see the migration). Salary changes are audited. */
class StaffContract extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'starts_on', 'ends_on', 'base_salary', 'status', 'renewed_from_id', 'raise_amount', 'raise_percent', 'notes', 'reminded_at', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'base_salary' => 'decimal:2', 'raise_amount' => 'decimal:2', 'raise_percent' => 'decimal:2', 'reminded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StaffContractItem::class);
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }
}
