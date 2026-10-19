<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Shared\Traits\Auditable;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use Auditable, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'starts_on', 'ends_on', 'is_current'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'is_current' => 'boolean'];
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    protected static function newFactory(): AcademicYearFactory
    {
        return AcademicYearFactory::new();
    }
}
