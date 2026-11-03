<?php

declare(strict_types=1);

namespace App\Modules\Transport\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A round of the school day (first / second / third shift): the students of a round ride together. */
class TransportRound extends Model
{
    use Auditable;

    protected $fillable = ['name_ar', 'name_en', 'starts_at', 'ends_at', 'sort_order'];

    /** @return BelongsToMany<Bus, $this> */
    public function buses(): BelongsToMany
    {
        return $this->belongsToMany(Bus::class, 'bus_round', 'transport_round_id', 'bus_id');
    }
}
