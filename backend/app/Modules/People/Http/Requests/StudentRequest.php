<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $student = $this->route('student') ? Student::find($this->route('student')) : null;

        return [
            'student_number' => ['required', 'string', 'max:50', Rule::unique('students', 'student_number')->ignore($student?->id)],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'student_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student?->user_id)],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_email' => ['nullable', 'email', 'max:255', $this->guardianEmailRule()],
        ];
    }

    /** The parent email must be new, or already belong to a parent account (one parent, many children). */
    private function guardianEmailRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === $this->input('student_email')) {
                $fail(__('people.guardian_email_same_as_student'));

                return;
            }

            $existing = User::withTrashed()->where('email', $value)->first();
            if ($existing && ($existing->trashed() || $existing->role !== Role::Guardian)) {
                $fail(__('people.guardian_email_conflict'));
            }
        };
    }

    /** Emails are compared case-insensitively. */
    protected function prepareForValidation(): void
    {
        foreach (['student_email', 'guardian_email'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => mb_strtolower(trim($value))]);
            }
        }
    }
}
