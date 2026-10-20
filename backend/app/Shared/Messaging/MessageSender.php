<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

/**
 * Sends a text message to a phone number (WhatsApp, SMS, ...). A provider is one class implementing this
 * interface, registered in config/messaging.php and selected with MESSAGING_DRIVER. send() throws when the
 * provider rejects the message.
 */
interface MessageSender
{
    public function send(string $phone, string $text): void;
}
