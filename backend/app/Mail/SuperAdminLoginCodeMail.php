<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The one-time code of a Super Admin sign-in. */
class SuperAdminLoginCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $accountName,
        public readonly int $minutes,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function build(): static
    {
        return $this->subject(__('auth.admin_code_subject'))->view('emails.super-admin-login-code');
    }
}
