<?php

declare(strict_types=1);

namespace App\Modules\Transport\Models;

use Illuminate\Database\Eloquent\Model;

/** One student's tick for one round of one bus on one day: boarded, or did not board. */
class BusBoarding extends Model
{
    /** @var list<string> */
    protected $fillable = ['date', 'bus_id', 'transport_round_id', 'student_id', 'status', 'marked_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
