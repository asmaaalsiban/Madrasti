<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Shared\Traits\Auditable;
use Database\Factories\GradeLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeLevel extends Model
{
    /** @use HasFactory<GradeLevelFactory> */
    use Auditable, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['name_ar', 'name_en', 'code', 'sort_order', 'periods_per_day', 'class_teacher_grade'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['class_teacher_grade' => 'boolean'];
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    protected static function newFactory(): GradeLevelFactory
    {
        return GradeLevelFactory::new();
    }
}
