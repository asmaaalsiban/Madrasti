<?php

use App\Shared\Payments\DemoPaymentGateway;

return [
    /*
    | Which provider takes online payments from parents. "demo" is a fake card form for development (it is off in
    | production). Set PAYMENT_GATEWAY to "none" to turn online payment off.
    | To add a real provider (Tap, PayTabs, HyperPay, MyFatoorah...): create a class implementing
    | App\Shared\Payments\PaymentGateway, add it to "drivers" under its key, put its keys in "providers"
    | (read from .env), and set PAYMENT_GATEWAY. Its webhook URL is  POST /api/payments/webhook/{key}  on the school's domain.
    */
    'driver' => env('PAYMENT_GATEWAY', 'demo'),

    'drivers' => [
        'demo' => DemoPaymentGateway::class,
    ],

    // Credentials of real providers, e.g. 'tap' => ['secret_key' => env('TAP_SECRET_KEY'), 'webhook_secret' => env('TAP_WEBHOOK_SECRET')].
    'providers' => [],
];
