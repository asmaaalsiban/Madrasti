<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('teacher');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Emails are compared case-insensitively. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
