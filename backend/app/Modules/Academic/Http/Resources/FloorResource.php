<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $byKind = $this->rooms->groupBy('kind');

        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'name' => $this->name,
            'level' => $this->level,
            'gender' => $this->gender,
            'effective_gender' => $this->effectiveGender(),
            'grade_from' => $this->gradeFrom?->only(['id', 'name_ar', 'name_en']),
            'grade_to' => $this->gradeTo?->only(['id', 'name_ar', 'name_en']),
            'counts' => [
                'classroom' => $byKind->get('classroom', collect())->count(),
                'science_lab' => $byKind->get('science_lab', collect())->count(),
                'computer_lab' => $byKind->get('computer_lab', collect())->count(),
            ],
            'rooms' => $this->rooms->map->only(['id', 'name', 'kind', 'capacity'])->values(),
        ];
    }
}
