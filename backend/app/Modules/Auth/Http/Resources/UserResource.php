<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'locale' => $this->locale,
            'avatar_path' => $this->avatar_path,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            // Only for parents: the children they can follow.
            'children' => $this->whenLoaded('children', fn () => $this->children->map(fn ($student) => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'name_ar' => $student->name_ar,
                'name_en' => $student->name_en,
                // The child's login email, so a parent who lost it finds it here.
                'email' => $student->user?->email,
                'classroom' => $student->enrollments->sortByDesc('academic_year_id')->first()?->classroom?->name,
            ])->values()),
        ];
    }
}
