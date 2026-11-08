<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->staffProfile;
        $subject = $profile?->subject;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'staff_type' => $profile?->staff_type->value,
            'category' => $profile?->staff_type->category(),
            'subject' => $subject ? ['id' => $subject->id, 'name_ar' => $subject->name_ar, 'name_en' => $subject->name_en] : null,
            'floor' => $profile?->floor,
            'grade_from' => $profile?->gradeFrom?->only(['id', 'name_ar', 'name_en']),
            'grade_to' => $profile?->gradeTo?->only(['id', 'name_ar', 'name_en']),
            'extra_subjects' => $profile?->extraSubjects->map(fn ($x) => ['subject_id' => $x->subject_id, 'grade_ids' => $x->grade_ids ?? []])->values() ?? [],
            'degree' => $profile?->degree,
            'specialization' => $profile?->specialization,
            'university' => $profile?->university,
            'graduation_year' => $profile?->graduation_year,
            'experience_years' => $profile?->experience_years,
            'courses' => $profile?->courses,
            'weekly_load' => isset($this->teaching_load) ? (int) $this->teaching_load : null,
            'teaches_gender' => $profile?->teaches_gender,
            'classroom_ids' => $profile?->classrooms->pluck('id')->sort()->values() ?? [],
            'classrooms' => $profile?->classrooms->sortBy('name')->map->only(['id', 'name'])->values() ?? [],
        ];
    }
}
