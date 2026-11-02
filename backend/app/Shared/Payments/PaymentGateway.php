<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use App\Modules\Finance\Models\OnlinePayment;
use Illuminate\Http\Request;

/**
 * An online payment provider (Tap, PayTabs, HyperPay, MyFatoorah, ...). A provider is one class implementing this
 * interface, registered in config/payments.php and selected with PAYMENT_GATEWAY. The school never sees card numbers:
 * a real provider takes the parent to its own hosted page (or embeds its own fields) and tells us the result by webhook.
 */
interface PaymentGateway
{
    /** The key used in config and in the webhook URL, e.g. "tap". */
    public function name(): string;

    /** Opens a payment with the provider for a pending OnlinePayment and says how the parent continues. */
    public function start(OnlinePayment $payment, string $returnUrl): Checkout;

    /**
     * Reads the provider's webhook: checks its signature (throw InvalidWebhook when it is not genuine) and says
     * which payment it is about and how it ended.
     *
     * @throws InvalidWebhook
     */
    public function parseWebhook(Request $request): GatewayEvent;
}
