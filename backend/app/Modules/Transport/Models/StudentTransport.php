<?php

declare(strict_types=1);

namespace App\Modules\Transport\Models;

use App\Modules\Academic\Models\Student;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where one student rides: a round, the bus that picks them up and the bus that takes them home (may be different, may be empty). */
class StudentTransport extends Model
{
    use Auditable;

    protected $table = 'student_transport';

    protected $fillable = ['student_id', 'transport_round_id', 'pickup_bus_id', 'dropoff_bus_id'];

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<TransportRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(TransportRound::class, 'transport_round_id');
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
