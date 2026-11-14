<?php

declare(strict_types=1);

namespace App\Central\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pricing plan of the public website, managed by the Super Admin. Lives in the central database and is not tied to a school's limits
 * (a school's student limit is still set on the school itself).
 */
class Plan extends Model
{
    /** Always the central database, also when read from inside a school's tenancy. */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    protected $fillable = [
        'name_ar', 'name_en', 'price_monthly', 'price_yearly', 'currency', 'max_students', 'description_ar', 'description_en',
        'features_ar', 'features_en', 'is_popular', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['features_ar' => 'array', 'features_en' => 'array', 'is_popular' => 'boolean', 'is_active' => 'boolean', 'price_monthly' => 'float', 'price_yearly' => 'float', 'max_students' => 'integer', 'sort_order' => 'integer'];
    }
}
