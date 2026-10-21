<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A term (semester) of an academic year; grades are entered per term. */
class Term extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['academic_year_id', 'name', 'starts_on', 'ends_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
