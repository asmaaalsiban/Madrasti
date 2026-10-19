<?php

namespace Database\Factories;

use App\Modules\Academic\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    protected $model = AcademicYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('20##/20##'),
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'is_current' => false,
        ];
    }
}
