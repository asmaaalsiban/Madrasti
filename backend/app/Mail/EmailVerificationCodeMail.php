<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The code that proves a parent owns the email they want on their account. */
class EmailVerificationCodeMail extends Mailable
{
    public function __construct(public readonly string $code, public readonly string $accountName, public readonly int $minutes, string $locale)
    {
        $this->locale($locale);
    }

    public function build(): static
    {
        return $this->subject(__('auth.email_mail_subject'))->view('emails.email-code');
    }
}
