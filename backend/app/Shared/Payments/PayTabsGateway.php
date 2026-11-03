<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use App\Modules\Finance\Models\OnlinePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayTabs (PT2) hosted payment page. The parent is sent to PayTabs to type the card, so no card number ever reaches us; PayTabs tells us
 * the result by a signed server-to-server callback and sends the parent back to the app. Money is only recorded after the callback.
 *
 * .env: PAYMENT_GATEWAY=paytabs, PAYTABS_PROFILE_ID, PAYTABS_SERVER_KEY, PAYTABS_BASE_URL (the region's host, e.g. https://secure-jordan.paytabs.com,
 * https://secure.paytabs.sa, https://secure.paytabs.com for UAE, https://secure-egypt.paytabs.com, https://secure-global.paytabs.com).
 */
class PayTabsGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'paytabs';
    }

    public function start(OnlinePayment $payment, string $returnUrl): Checkout
    {
        $currency = (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
        $response = Http::withHeaders(['authorization' => $this->serverKey()])->acceptJson()->asJson()->timeout(20)->post($this->baseUrl().'/payment/request', [
            'profile_id' => $this->profileId(),
            'tran_type' => 'sale',
            'tran_class' => 'ecom',
            'cart_id' => $payment->reference,
            'cart_currency' => $currency,
            'cart_amount' => (float) $payment->amount,
            'cart_description' => 'School fees',
            'callback' => url('/api/central/payments/webhook/'.tenant('id').'/paytabs'),
            'return' => $returnUrl,
            'customer_details' => ['name' => $payment->payer_name],
        ]);
        $url = $response->json('redirect_url');
        if (! $response->successful() || ! is_string($url) || $url === '') {
            throw new RuntimeException('PayTabs did not open the payment: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return new Checkout(Checkout::REDIRECT, $url, (string) $response->json('tran_ref'));
    }

    /**
     * PayTabs signs the raw body with the server key (HMAC-SHA256, hex, header "Signature"). The payment must exist, still be ours, and the
     * amount PayTabs charged must be what we asked for: a paid answer for a smaller amount is refused.
     */
    public function parseWebhook(Request $request): GatewayEvent
    {
        if (app(PayTabsSettings::class)->effective() === null) {
            throw new InvalidWebhook('PayTabs is not set up for this school.');
        }
        $body = $request->getContent();
        $given = (string) $request->header('Signature', '');
        if ($given === '' || ! hash_equals(hash_hmac('sha256', $body, $this->serverKey()), strtolower($given))) {
            throw new InvalidWebhook('The PayTabs signature does not match.');
        }
        $data = json_decode($body, true);
        $reference = is_array($data) ? (string) ($data['cart_id'] ?? '') : '';
        $payment = $reference === '' ? null : OnlinePayment::where('reference', $reference)->where('provider', 'paytabs')->first();
        if ($payment === null) {
            throw new InvalidWebhook('PayTabs spoke about a payment we do not know.');
        }
        $result = (array) ($data['payment_result'] ?? []);
        $paid = ($result['response_status'] ?? '') === 'A'; // A = authorised/approved
        if ($paid && abs((float) ($data['cart_amount'] ?? 0) - (float) $payment->amount) > 0.001) {
            throw new InvalidWebhook('PayTabs charged a different amount than was asked.');
        }

        return new GatewayEvent($reference, $paid, isset($data['tran_ref']) ? (string) $data['tran_ref'] : null, $paid ? null : (string) ($result['response_message'] ?? $result['response_code'] ?? 'declined'));
    }

    /** This school's own account (or the platform's from .env). */
    private function account(): array
    {
        return app(PayTabsSettings::class)->effective() ?? throw new RuntimeException('PayTabs is not set up for this school.');
    }

    private function serverKey(): string
    {
        return $this->account()['server_key'];
    }

    private function profileId(): string
    {
        return $this->account()['profile_id'];
    }

    private function baseUrl(): string
    {
        return rtrim($this->account()['base_url'], '/');
    }
}
