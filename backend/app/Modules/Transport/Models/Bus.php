<?php

declare(strict_types=1);

namespace App\Modules\Transport\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/** A school bus: its driver, the area it covers (from → to) and the rounds it serves. */
class Bus extends Model
{
    use Auditable;

    protected $fillable = ['number', 'driver_id', 'driver_name', 'driver_phone', 'capacity', 'area_from', 'area_to', 'notes', 'is_active'];

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

    /** @return BelongsToMany<TransportRound, $this> */
    public function rounds(): BelongsToMany
    {
        return $this->belongsToMany(TransportRound::class, 'bus_round', 'bus_id', 'transport_round_id')->withPivot('escort_id');
    }

    /** The escort of this bus in one round (each round of a bus has its own escort), or null. */
    public function escortFor(int $roundId): ?User
    {
        $id = DB::table('bus_round')->where('bus_id', $this->id)->where('transport_round_id', $roundId)->value('escort_id');

        return $id ? User::find($id) : null;
    }

    /** Whether this person is the escort of the bus in that round. */
    public function isEscortOf(int $roundId, int $userId): bool
    {
        return DB::table('bus_round')->where('bus_id', $this->id)->where('transport_round_id', $roundId)->where('escort_id', $userId)->exists();
    }
}
