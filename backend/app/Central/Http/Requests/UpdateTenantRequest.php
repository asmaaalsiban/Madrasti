<?php

declare(strict_types=1);

namespace App\Central\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by the super-admin middleware
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_locale' => ['sometimes', 'in:ar,en'],
            'is_active' => ['sometimes', 'boolean'],
            'subscription_ends_at' => ['nullable', 'date'],
        ];
    }
}
