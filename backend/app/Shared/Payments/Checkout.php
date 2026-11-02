<?php

declare(strict_types=1);

namespace App\Shared\Payments;

/** How the parent continues after a payment was opened: `redirect` to the provider's page, or `demo_card` (the demo form). */
final class Checkout
{
    public const REDIRECT = 'redirect';

    public const DEMO_CARD = 'demo_card';

    public function __construct(public readonly string $mode, public readonly ?string $url = null, public readonly ?string $providerReference = null) {}
}
