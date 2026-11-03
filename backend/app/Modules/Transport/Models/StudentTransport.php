<?php

declare(strict_types=1);

namespace App\Modules\Transport\Models;

use App\Modules\Academic\Models\Student;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where one student rides: the morning round and bus to school, the evening round and bus home (may be different, may be empty). */
class StudentTransport extends Model
{
    use Auditable;

    protected $table = 'student_transport';

    protected $fillable = ['student_id', 'pickup_round_id', 'pickup_bus_id', 'dropoff_round_id', 'dropoff_bus_id'];

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<TransportRound, $this> */
    public function pickupRound(): BelongsTo
    {
        return $this->belongsTo(TransportRound::class, 'pickup_round_id');
    }

    /** @return BelongsTo<TransportRound, $this> */
    public function dropoffRound(): BelongsTo
    {
        return $this->belongsTo(TransportRound::class, 'dropoff_round_id');
    }

    /** @return BelongsTo<Bus, $this> */
    public function pickupBus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'pickup_bus_id');
    }

    /** @return BelongsTo<Bus, $this> */
    public function dropoffBus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'dropoff_bus_id');
    }
}
