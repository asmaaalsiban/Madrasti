<?php

namespace Database\Factories;

use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name_ar' => fake()->unique()->word(),
            'name_en' => fake()->unique()->word(),
        ];
    }
}
