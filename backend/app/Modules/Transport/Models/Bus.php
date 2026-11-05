<?php

declare(strict_types=1);

namespace App\Modules\Transport\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A school bus: its driver, the area it covers (from → to) and the rounds it serves. */
class Bus extends Model
{
    use Auditable;

    protected $fillable = ['number', 'driver_id', 'escort_id', 'driver_name', 'driver_phone', 'capacity', 'area_from', 'area_to', 'notes', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    /** The driver, when chosen from the staff. */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** The escort (a teacher or bus supervisor who rides with the students). */
    public function escort(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escort_id');
    }

    /** @return BelongsToMany<TransportRound, $this> */
    public function rounds(): BelongsToMany
    {
        return $this->belongsToMany(TransportRound::class, 'bus_round', 'bus_id', 'transport_round_id');
    }
}
