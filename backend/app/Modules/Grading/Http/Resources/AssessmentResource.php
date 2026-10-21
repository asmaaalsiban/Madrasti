<?php

declare(strict_types=1);

namespace App\Modules\Grading\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'max_score' => $this->max_score,
            'weight' => $this->weight,
            'held_on' => $this->held_on?->format('Y-m-d'),
            'is_published' => $this->is_published,
            'classroom' => $this->classroom?->only(['id', 'name']),
            'subject' => $this->subject?->only(['id', 'name_ar', 'name_en']),
            'term' => $this->term?->only(['id', 'name']),
            'teacher' => $this->teacher?->only(['id', 'name']),
            // Progress of the score entry (only in the list).
            'scored' => $this->when(isset($this->scored_count), fn () => (int) $this->scored_count),
            'students' => $this->when(isset($this->students_count), fn () => (int) $this->students_count),
        ];
    }
}
