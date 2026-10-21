<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Room extends Model
{
    use Auditable;

    /** Kinds a floor can be given a count of. */
    public const KINDS = ['classroom', 'science_lab', 'computer_lab'];

    /**
     * @var list<string>
     */
    protected $fillable = ['floor_id', 'name', 'kind', 'type', 'capacity'];

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** A room is in use when a classroom stays in it or a timetable / exam uses it. */
    public function isInUse(): bool
    {
        return DB::table('classrooms')->where('room_id', $this->id)->exists()
            || DB::table('timetable_entries')->where('room_id', $this->id)->exists()
            || DB::table('exam_sessions')->where('room_id', $this->id)->exists();
    }
}
