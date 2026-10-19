<?php

namespace Database\Factories;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    protected $model = Classroom::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('#-?'),
            'grade_level_id' => GradeLevel::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'capacity' => 30,
        ];
    }
}
