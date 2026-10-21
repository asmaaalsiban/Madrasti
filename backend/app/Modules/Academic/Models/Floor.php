<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Floor extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['building_id', 'name', 'level', 'gender', 'grade_from_id', 'grade_to_id'];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function gradeFrom(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_from_id');
    }

    public function gradeTo(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'grade_to_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('id');
    }

    /** Own gender, otherwise the building's. */
    public function effectiveGender(): string
    {
        return $this->gender ?? $this->building->gender;
    }
}
