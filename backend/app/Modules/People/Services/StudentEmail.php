<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds the login email of a student, like a university email: <english first name>.<student code>@<domain>
 * (e.g. ahmad.d1001@school2.edu). Without an English name it is <student code>@<domain>.
 * The domain is STUDENT_EMAIL_DOMAIN, or <school code>.edu.
 */
class StudentEmail
{
    public function generate(string $studentNumber, ?string $nameEn, string $schoolCode): string
    {
        $first = $this->slug(Str::of((string) $nameEn)->trim()->before(' ')->toString());
        $code = $this->slug($studentNumber) ?: 'student';
        $local = $first !== '' ? "{$first}.{$code}" : $code;
        $domain = config('accounts.student_email_domain') ?: "{$schoolCode}.edu";

        // Never reuse the email of any account, including deleted ones (the database keeps them unique).
        $email = "{$local}@{$domain}";
        for ($n = 2; User::withTrashed()->where('email', $email)->exists(); $n++) {
            $email = "{$local}-{$n}@{$domain}";
        }

        return $email;
    }

    /** Lower case a-z and 0-9 only; anything that is not Latin (e.g. Arabic) is dropped. */
    private function slug(string $value): string
    {
        $latin = preg_replace('/[^\p{Latin}\p{N}]/u', '', $value) ?? '';

        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($latin))) ?? '';
    }
}
