<?php

declare(strict_types=1);

namespace App\Central\Http\Requests;

use App\Central\Rules\SchoolCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterSchoolRequest extends FormRequest
{
    /** Checked before validation so a closed registration reveals nothing (e.g. which codes exist). */
    public function authorize(): bool
    {
        return (bool) config('tenancy.registration_enabled');
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->json(['message' => __('registration.disabled')], 403));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'school_name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', new SchoolCode,
                Rule::unique('tenants', 'id'),
            ],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
