<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Requests;

use App\Shared\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'homeroom_teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Teacher->value)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
