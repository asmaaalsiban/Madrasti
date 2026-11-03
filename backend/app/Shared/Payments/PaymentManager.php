<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use Illuminate\Contracts\Container\Container;

/** Finds the payment provider chosen in config/payments.php. Online payment is "enabled" only when one is configured. */
class PaymentManager
{
    public function __construct(private readonly Container $app) {}

    /** The configured provider's key, or null when online payment is off (no provider, or the demo in production). */
    public function driverName(): ?string
    {
        // A school with its own PayTabs account switched on pays through it, whatever .env says.
        if (app(PayTabsSettings::class)->ownEnabled() && isset(config('payments.drivers')['paytabs'])) {
            return 'paytabs';
        }
        $name = config('payments.driver');
        if (! $name || ! isset(config('payments.drivers')[$name])) {
            return null;
        }
        if ($name === 'demo' && $this->app->environment('production')) {
            return null;
        }

        return $name;
    }

    /**
     * Every way this school lets a parent pay online, so the school and the parent can choose: `card` (the configured gateway) and
     * `bank_transfer` (transfer / CliQ with a proof that the accountant confirms).
     *
     * @return list<array{key: string, provider: string|null}>
     */
    public function methods(): array
    {
        $out = [];
        if ($this->driverName() !== null) {
            $out[] = ['key' => 'card', 'provider' => $this->driverName()];
        }
        if (app(BankTransferSettings::class)->enabled()) {
            $out[] = ['key' => 'bank_transfer', 'provider' => null];
        }

        return $out;
    }

    public function enabled(): bool
    {
        return $this->driverName() !== null;
    }

    public function gateway(?string $name = null): PaymentGateway
    {
        $name ??= $this->driverName();
        abort_unless($name !== null && $name === $this->driverName(), 404);

        return $this->app->make(config('payments.drivers')[$name]);
    }
}
