<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing a student is evaluated on (discipline, respect...). */
class BehaviorCriterion extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name_ar', 'name_en', 'sort_order', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
