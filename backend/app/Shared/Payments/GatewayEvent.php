<?php

declare(strict_types=1);

namespace App\Shared\Payments;

/** What a provider told us about one payment: `reference` is ours (the OnlinePayment reference we sent it). */
final class GatewayEvent
{
    public function __construct(
        public readonly string $reference,
        public readonly bool $paid,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureReason = null,
    ) {}
}
