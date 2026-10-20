<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

use Illuminate\Support\Facades\Log;

/** Default driver for development: writes the message to the log instead of sending it. */
class LogMessageSender implements MessageSender
{
    public function send(string $phone, string $text): void
    {
        Log::info('Message to '.$phone.': '.$text);
    }
}
