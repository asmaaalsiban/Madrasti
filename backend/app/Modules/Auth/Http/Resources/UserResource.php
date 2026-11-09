<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Models\User;
use App\Modules\Transport\Models\Bus;
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
            // How the person arranged their own icons and menus (see LayoutController).
            'layout' => (object) ($this->layout ?? []),
            // Only for staff: what kind of employee (the app shows the tools that fit).
            'staff_type' => $this->whenLoaded('staffProfile', fn () => $this->staffProfile?->staff_type->value),
            // Only for staff: whether they drive a bus or ride as its escort (the app then shows "My bus").
            'bus_duty' => $this->whenLoaded('staffProfile', fn () => array_values(array_filter([
                Bus::where('is_active', true)->where('driver_id', $this->id)->exists() ? 'driver' : null,
                Bus::where('is_active', true)->whereIn('id', Bus::escortedBusIds((int) $this->id))->exists() ? 'escort' : null,
            ]))),
            // Only for staff: the screens the principal switched off for them.
            'hidden_features' => $this->whenLoaded('staffProfile', fn () => $this->staffProfile?->hidden_features ?? []),
            // Only for staff: the subject they teach (a head of department: the department).
            'subject' => $this->whenLoaded('staffProfile', fn () => $this->staffProfile?->subject?->only(['id', 'name_ar', 'name_en'])),
            // Only for students: their section (latest enrolment) and the id of their student record (their grades live under it).
            'classroom' => $this->whenLoaded('student', fn () => $this->student?->enrollments->sortByDesc('academic_year_id')->first()?->classroom?->name),
            'student_id' => $this->whenLoaded('student', fn () => $this->student?->id),
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
