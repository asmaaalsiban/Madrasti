<?php

declare(strict_types=1);

namespace App\Modules\Transport\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A school bus: its driver, the area it covers (from → to) and the rounds it serves. */
class Bus extends Model
{
    use Auditable;

    protected $fillable = ['number', 'driver_name', 'driver_phone', 'capacity', 'area_from', 'area_to', 'notes', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    /** @return BelongsToMany<TransportRound, $this> */
    public function rounds(): BelongsToMany
    {
        return $this->belongsToMany(TransportRound::class, 'bus_round', 'bus_id', 'transport_round_id');
    }
}
