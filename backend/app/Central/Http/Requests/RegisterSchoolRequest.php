<?php

declare(strict_types=1);

namespace App\Central\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RegisterSchoolRequest extends FormRequest
{
    /** Subdomains that must never be handed to a school. */
    public const RESERVED_CODES = [
        'www', 'admin', 'api', 'app', 'central', 'mail', 'root', 'support', 'static', 'assets', 'school', 'schools',
    ];

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
                'required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$/',
                Rule::notIn(self::RESERVED_CODES),
                Rule::unique('tenants', 'id'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $domain = $value.'.'.config('tenancy.base_domain');
                    if (DB::table('domains')->where('domain', $domain)->exists()) {
                        $fail(__('registration.code_taken'));
                    }
                },
            ],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
