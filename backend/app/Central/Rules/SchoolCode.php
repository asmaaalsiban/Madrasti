<?php

declare(strict_types=1);

namespace App\Central\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A school code is what people type to reach their school (and the tenant id): it must match what the
 * website accepts, so a school created from the CLI or the Super Admin API is always reachable.
 */
class SchoolCode implements ValidationRule
{
    /** Same pattern as the frontend (src/tenant/school.js): 3-30 chars, lowercase letters/digits/hyphens. */
    public const PATTERN = '/^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$/';

    /** Codes that must never be handed to a school. */
    public const RESERVED = [
        'www', 'admin', 'api', 'app', 'central', 'mail', 'root', 'support', 'static', 'assets', 'school', 'schools',
    ];

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1 && ! in_array($code, self::RESERVED, true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('registration.invalid_code'));
        }
    }
}
