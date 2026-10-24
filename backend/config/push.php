<?php

/*
| Web Push (notifications on the phone/computer of people who allowed them). Create the keys once with
| `php artisan push:generate-keys` and put them in .env; without them push is switched off and nothing else changes.
*/
return [
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
];
