<?php

declare(strict_types=1);

namespace App\Modules\Motivation\Models;

use Illuminate\Database\Eloquent\Model;

/** A ready-made category for giving (or taking) points, e.g. "Helping a classmate" +2. */
class PointCategory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name_ar', 'name_en', 'points', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['points' => 'integer', 'is_active' => 'boolean'];
    }
}
