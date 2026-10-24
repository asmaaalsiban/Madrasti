<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The one-time code of a "forgot my password" request. For a student it goes to their parent, who gives it to the child. */
class PasswordResetCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $accountName,
        public readonly bool $forStudent,
        public readonly int $minutes,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function build(): static
    {
        return $this->subject(__('auth.reset_mail_subject', ['name' => $this->accountName]))->view('emails.password-code');
    }
}
