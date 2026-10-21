<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeachingAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'weekly_periods' => $this->weekly_periods,
            'classroom_id' => $this->classroom_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'classroom' => $this->classroom?->only(['id', 'name']),
            'subject' => $this->subject?->only(['id', 'name_ar', 'name_en']),
            'teacher' => $this->teacher?->only(['id', 'name']),
        ];
    }
}
