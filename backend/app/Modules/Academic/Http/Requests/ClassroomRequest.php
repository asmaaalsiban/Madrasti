<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Requests;

use App\Models\User;
use App\Shared\Enums\StaffType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ClassroomRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'homeroom_teacher_id' => ['nullable', 'bail', 'integer', $this->homeroomTeacherRule()],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /** Only active, not deleted staff of a type that may be a homeroom teacher (teacher, head of department). */
    private function homeroomTeacherRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $allowed = User::query()->whereKey($value)->where('is_active', true)
                ->whereHas('staffProfile', fn ($q) => $q->whereIn('staff_type', StaffType::homeroomValues()))
                ->exists();

            if (! $allowed) {
                $fail(__('validation.exists', ['attribute' => $attribute]));
            }
        };
    }
}
