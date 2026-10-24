<?php

declare(strict_types=1);

namespace App\Shared\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** The real sender (minishlink/web-push with the school's VAPID keys). */
class WebPushTransport implements PushTransport
{
    public function send(array $subscriptions, array $payload): array
    {
        $webPush = new WebPush(['VAPID' => [
            'subject' => config('push.subject'), 'publicKey' => config('push.public_key'), 'privateKey' => config('push.private_key'),
        ]]);
        foreach ($subscriptions as $s) {
            $webPush->queueNotification(Subscription::create(['endpoint' => $s['endpoint'], 'publicKey' => $s['p256dh'], 'authToken' => $s['auth']]), json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $gone = [];
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $gone[] = $report->getEndpoint();
            }
        }

        return $gone;
    }
}
