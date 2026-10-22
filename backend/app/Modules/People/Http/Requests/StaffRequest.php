<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\Academic\Models\GradeLevel;
use App\Shared\Enums\StaffType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('staff');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($id)],
            'is_active' => ['sometimes', 'boolean'],
            'staff_type' => ['required', Rule::enum(StaffType::class)],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'floor' => ['nullable', 'string', 'max:20'],
            'grade_from_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
            'grade_to_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
            'teaches_gender' => ['nullable', 'in:male,female'],
            'classroom_ids' => ['nullable', 'array', 'max:100'],
            'classroom_ids.*' => ['integer', 'exists:classrooms,id'],
        ];
    }

    /** The grades a teacher teaches have both ends (or none), from the lower to the higher. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $from = $this->input('grade_from_id');
            $to = $this->input('grade_to_id');
            if (($from === null) !== ($to === null)) {
                $validator->errors()->add('grade_to_id', __('academic.floor_grade_range_incomplete'));
            } elseif ($from !== null && GradeLevel::findOrFail($from)->sort_order > GradeLevel::findOrFail($to)->sort_order) {
                $validator->errors()->add('grade_to_id', __('academic.floor_grade_range_reversed'));
            }
        }];
    }

    /** Emails are compared case-insensitively. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
