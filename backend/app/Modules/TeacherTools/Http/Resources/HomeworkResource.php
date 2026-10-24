<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Resources;

use App\Modules\TeacherTools\Models\HomeworkAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HomeworkAssignment */
class HomeworkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'due_on' => $this->due_at->format('Y-m-d'),
            'has_image' => $this->attachment_path !== null,
            'published_at' => $this->published_at?->toIso8601String(),
            'classroom' => $this->classroom->only(['id', 'name']),
            'subject' => $this->subject->only(['id', 'name_ar', 'name_en']),
            'teacher' => $this->teacher->only(['id', 'name']),
        ];
    }
}
