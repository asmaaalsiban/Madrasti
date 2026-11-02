<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use App\Modules\Finance\Models\OnlinePayment;
use Illuminate\Http\Request;

/**
 * Development driver: the parent types a card into our own form and no money moves. 4242 4242 4242 4242 is approved,
 * 4000 0000 0000 0002 is declined, any other valid number is approved. It never stores or logs the card, and it is
 * switched off in production (see PaymentManager). A real provider replaces it without changing any screen.
 */
class DemoPaymentGateway implements PaymentGateway
{
    public const DECLINED_CARD = '4000000000000002';

    public function name(): string
    {
        return 'demo';
    }

    public function start(OnlinePayment $payment, string $returnUrl): Checkout
    {
        return new Checkout(Checkout::DEMO_CARD, null, 'demo-'.$payment->reference);
    }

    /** The demo has no provider behind it, so there is no webhook. */
    public function parseWebhook(Request $request): GatewayEvent
    {
        throw new InvalidWebhook('The demo gateway has no webhook.');
    }

    /** Judges a card typed into the demo form. */
    public function charge(OnlinePayment $payment, string $number): GatewayEvent
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $ref = 'demo-'.$payment->reference;
        if (! $this->luhn($digits)) {
            return new GatewayEvent($payment->reference, false, $ref, 'invalid_card');
        }
        if ($digits === self::DECLINED_CARD) {
            return new GatewayEvent($payment->reference, false, $ref, 'declined');
        }

        return new GatewayEvent($payment->reference, true, $ref);
    }

    private function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19) {
            return false;
        }
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $d) {
            $n = (int) $d * ($i % 2 === 1 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }

        return $sum % 10 === 0;
    }
}
