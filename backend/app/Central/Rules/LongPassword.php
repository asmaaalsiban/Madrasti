<?php

declare(strict_types=1);

namespace App\Central\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Super Admin password must be at least 14 characters: the account reaches every school. */
class LongPassword implements ValidationRule
{
    public const MIN = 14;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < self::MIN) {
            $fail('auth.admin_password_short')->translate(['min' => self::MIN]);
        }
    }
}
