<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassroomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $teacher = $this->homeroomTeacher;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'gender' => $this->gender,
            'room' => $this->room ? ['id' => $this->room->id, 'name' => $this->room->name, 'floor' => $this->room->floor?->name, 'building' => $this->room->floor?->building?->name] : null,
            'grade_level_id' => $this->grade_level_id,
            'academic_year_id' => $this->academic_year_id,
            'grade_level' => $this->gradeLevel?->only(['id', 'name_ar', 'name_en']),
            'academic_year' => $this->academicYear?->only(['id', 'name']),
            'homeroom_teacher' => $teacher ? ['id' => $teacher->id, 'name' => $teacher->name] : null,
        ];
    }
}
