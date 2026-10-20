<?php

use App\Shared\Messaging\LogMessageSender;

return [
    /*
    | Which provider sends the messages to parents. "log" only writes them to the log.
    | To add WhatsApp / Twilio / SMS: create a class implementing App\Shared\Messaging\MessageSender,
    | add it to "drivers" and set MESSAGING_DRIVER.
    */
    'driver' => env('MESSAGING_DRIVER', 'log'),

    'drivers' => [
        'log' => LogMessageSender::class,
    ],
];
