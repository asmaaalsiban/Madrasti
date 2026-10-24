<?php

declare(strict_types=1);

namespace App\Shared\Push;

/** Sends one web-push message to many browsers. Returns the endpoints the push service says are gone (to forget them). */
interface PushTransport
{
    /**
     * @param  list<array{endpoint: string, p256dh: string, auth: string}>  $subscriptions
     * @param  array{title: string, body: string, url: string}  $payload
     * @return list<string>
     */
    public function send(array $subscriptions, array $payload): array;
}
