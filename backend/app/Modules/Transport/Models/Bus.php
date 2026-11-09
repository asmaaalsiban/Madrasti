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

    /** The escort of this bus in one round on a day (default today): the stand-in of that day if there is one, else the usual escort, or null. */
    public function escortFor(int $roundId, ?string $date = null): ?User
    {
        $id = DB::table('bus_round_substitutes')->where('date', $date ?? now()->toDateString())->where('bus_id', $this->id)->where('transport_round_id', $roundId)->value('escort_id')
            ?? DB::table('bus_round')->where('bus_id', $this->id)->where('transport_round_id', $roundId)->value('escort_id');

        return $id ? User::find($id) : null;
    }

    /** The usual escort of a round (what the principal set), whoever stands in today. */
    public function usualEscortFor(int $roundId): ?User
    {
        $id = DB::table('bus_round')->where('bus_id', $this->id)->where('transport_round_id', $roundId)->value('escort_id');

        return $id ? User::find($id) : null;
    }

    /** Whether this person is the escort of the bus in that round on that day (the usual escort is not, on a day she has a stand-in). */
    public function isEscortOf(int $roundId, int $userId, ?string $date = null): bool
    {
        return $this->escortFor($roundId, $date)?->id === $userId;
    }

    /**
     * Ids of the active buses where this person is the escort of at least one round on that day (default today), counting stand-ins.
     *
     * @return list<int>
     */
    public static function escortedBusIds(int $userId, ?string $date = null): array
    {
        $subs = DB::table('bus_round_substitutes')->where('date', $date ?? now()->toDateString())->get()->keyBy(fn ($r) => $r->bus_id.'-'.$r->transport_round_id);

        return DB::table('bus_round')->get()
            ->filter(fn ($r) => (int) ($subs->get($r->bus_id.'-'.$r->transport_round_id)->escort_id ?? $r->escort_id) === $userId)
            ->pluck('bus_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
    }
}
