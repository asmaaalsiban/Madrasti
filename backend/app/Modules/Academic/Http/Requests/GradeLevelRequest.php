<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GradeLevelRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('sort_order') === null) {
            $this->merge(['sort_order' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
            'track' => ['nullable', 'string', 'max:60'],
        ];
    }
}
