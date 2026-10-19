<?php

namespace Database\Factories;

use App\Modules\Academic\Models\GradeLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeLevel>
 */
class GradeLevelFactory extends Factory
{
    protected $model = GradeLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_ar' => fake()->unique()->word(),
            'name_en' => fake()->unique()->word(),
            'sort_order' => fake()->numberBetween(1, 12),
        ];
    }
}
