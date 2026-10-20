<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Shared\Enums\StaffType;
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
