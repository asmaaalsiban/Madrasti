<?php

declare(strict_types=1);

namespace App\Modules\Motivation\Models;

use Illuminate\Database\Eloquent\Model;

/** A badge a student earns at a points total (points_required) or is given by hand. */
class Badge extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name_ar', 'name_en', 'icon', 'points_required', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['points_required' => 'integer', 'is_active' => 'boolean'];
    }
}
