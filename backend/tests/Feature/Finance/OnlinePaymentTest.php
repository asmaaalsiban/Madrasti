<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\OnlinePayment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Finance\Services\FeeBilling;
use App\Shared\Enums\Role;
use App\Shared\Payments\Checkout;
use App\Shared\Payments\GatewayEvent;
use App\Shared\Payments\InvalidWebhook;
use App\Shared\Payments\PaymentGateway;
use App\Shared\Payments\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class OnlinePaymentTest extends AcademicTestCase
{
    private User $parent;

    private Student $child;

    private StudentFee $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->child = Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'name_en' => 'Student', 'gender' => 'male']);
        $this->parent = User::factory()->role(Role::Guardian)->create(['name' => 'Abu Ali']);
        $this->child->guardians()->attach($this->parent->id);
        $billing = app(FeeBilling::class);
        $this->fee = $billing->createFee($this->child, $year, 'Tuition', 1000, 0, $billing->evenSchedule(100000, 4, '2026-09-01'));
        Sanctum::actingAs($this->parent);
    }

    private function start(array $extra = [])
    {
        return $this->postJson("/api/students/{$this->child->id}/online-payments", ['student_fee_id' => $this->fee->id, 'amount' => 250, 'payer_name' => 'Abu Ali', ...$extra]);
    }

    private function card(string $reference, string $number = '4242 4242 4242 4242', array $extra = [])
    {
        return $this->postJson("/api/online-payments/$reference/demo-confirm", ['card_number' => $number, 'expiry' => '12/40', 'cvc' => '123', 'holder' => 'Abu Ali', ...$extra]);
    }

    public function test_a_parent_pays_an_installment_with_a_card_and_the_receipt_is_in_the_payers_name(): void
    {
        $installment = $this->fee->installments()->orderBy('id')->first();
        $ref = $this->start(['installment_id' => $installment->id, 'payer_name' => 'Uncle Sami'])->assertCreated()->assertJsonPath('data.mode', 'demo_card')->json('data.reference');
        $this->assertSame(0, Payment::count()); // nothing is paid until the card is judged

        $this->card($ref)->assertOk()->assertJsonPath('data.status', 'paid');

        $payment = Payment::firstOrFail();
        $this->assertSame('online', $payment->method);
        $this->assertSame('Uncle Sami', $payment->payer_name);
        $this->assertSame('250.00', $payment->amount);
        $this->assertSame('250.00', $installment->fresh()->paid_amount);
        $this->getJson("/api/online-payments/$ref")->assertOk()->assertJsonPath('data.receipt', $payment->receiptNumber());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->parent->id)->where('data', 'like', '%online_payment%')->count());
    }

    public function test_a_declined_or_invalid_card_pays_nothing(): void
    {
        $ref = $this->start()->json('data.reference');
        $this->card($ref, '4000 0000 0000 0002')->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', 'declined');
        $this->assertSame(0, Payment::count());

        $again = $this->start()->json('data.reference');
        $this->card($again, '1234 5678 9012 3456')->assertOk()->assertJsonPath('data.failure_reason', 'invalid_card');
        $this->card($this->start()->json('data.reference'), '4242 4242 4242 4242', ['expiry' => '01/20'])->assertUnprocessable();
        $this->assertSame(0, Payment::count());
    }

    public function test_confirming_twice_pays_only_once(): void
    {
        $ref = $this->start()->json('data.reference');
        $this->card($ref)->assertOk();
        $this->card($ref)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, Payment::count());
        $this->assertSame('250.00', Payment::first()->amount);
    }

    public function test_the_amount_cannot_be_more_than_what_is_owed(): void
    {
        $installment = $this->fee->installments()->orderBy('id')->first(); // 250
        $this->start(['installment_id' => $installment->id, 'amount' => 300])->assertUnprocessable();
        $this->start(['amount' => 1000.01])->assertUnprocessable();
        $this->start(['amount' => 0])->assertUnprocessable();
        $this->start(['amount' => 1000])->assertCreated();
    }

    public function test_only_the_childs_own_parent_can_pay_and_only_for_their_own_payment(): void
    {
        $stranger = User::factory()->role(Role::Guardian)->create();
        $ref = $this->start()->json('data.reference');

        Sanctum::actingAs($stranger);
        $this->start()->assertForbidden();
        $this->card($ref)->assertNotFound();
        $this->getJson("/api/online-payments/$ref")->assertNotFound();

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->start()->assertForbidden();
    }

    public function test_money_taken_for_a_fee_that_was_paid_meanwhile_is_kept_for_review(): void
    {
        $ref = $this->start(['amount' => 1000])->json('data.reference');
        app(FeeBilling::class)->recordPayment($this->fee, 900, 'cash', now()->toDateString(), $this->parent); // the accountant took cash meanwhile

        $this->card($ref)->assertOk()->assertJsonPath('data.status', 'review');
        $this->assertSame(1, Payment::count());
        $this->assertSame(OnlinePayment::REVIEW, OnlinePayment::first()->status);
    }

    public function test_the_statement_says_whether_online_payment_is_available(): void
    {
        $this->getJson("/api/students/{$this->child->id}/fees")->assertOk()->assertJsonPath('data.online', ['enabled' => true, 'mode' => 'demo']);

        config(['payments.driver' => 'none']);
        $this->getJson("/api/students/{$this->child->id}/fees")->assertJsonPath('data.online.enabled', false);
        $this->start()->assertNotFound();
    }

    public function test_the_demo_gateway_is_off_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->assertFalse(app(PaymentManager::class)->enabled());
        $this->start()->assertNotFound();
    }

    public function test_a_real_provider_plugs_in_through_the_interface_and_its_webhook_settles_the_payment(): void
    {
        config(['payments.drivers.fake' => FakeProvider::class, 'payments.driver' => 'fake']);

        $start = $this->start()->assertCreated()->assertJsonPath('data.mode', 'redirect')->assertJsonPath('data.url', 'https://pay.example/checkout')->json('data');
        $this->assertSame(0, Payment::count());

        // The provider calls back without any login; a wrong signature is refused.
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/payments/webhook/fake', ['reference' => $start['reference'], 'paid' => true], ['X-Signature' => 'wrong'])->assertStatus(400);
        $this->assertSame(0, Payment::count());

        $ok = fn () => $this->postJson('/api/payments/webhook/fake', ['reference' => $start['reference'], 'paid' => true, 'id' => 'ch_1'], ['X-Signature' => 'secret'])->assertOk();
        $ok();
        $ok(); // providers retry: still one payment
        $this->assertSame(1, Payment::count());
        $this->assertSame('ch_1', Payment::first()->reference);

        // The demo form does not work when another provider is configured.
        Sanctum::actingAs($this->parent);
        $this->card($start['reference'])->assertNotFound();
        $this->postJson('/api/payments/webhook/demo')->assertNotFound();
    }
}

class FakeProvider implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function start(OnlinePayment $payment, string $returnUrl): Checkout
    {
        return new Checkout(Checkout::REDIRECT, 'https://pay.example/checkout');
    }

    public function parseWebhook(Request $request): GatewayEvent
    {
        if ($request->header('X-Signature') !== 'secret') {
            throw new InvalidWebhook('bad signature');
        }

        return new GatewayEvent((string) $request->input('reference'), (bool) $request->input('paid'), $request->input('id'));
    }

    private function paytabs(): void
    {
        config(['payments.driver' => 'paytabs', 'payments.providers.paytabs' => ['profile_id' => '1234', 'server_key' => 'SECRET-KEY', 'base_url' => 'https://secure-jordan.paytabs.com']]);
    }

    private function callback(string $reference, array $over = [], ?string $key = 'SECRET-KEY')
    {
        $body = json_encode(['tran_ref' => 'TST2611', 'cart_id' => $reference, 'cart_amount' => '250.00', 'cart_currency' => 'JOD', 'payment_result' => ['response_status' => 'A', 'response_message' => 'Authorised'], ...$over]);

        return $this->call('POST', '/api/payments/webhook/paytabs', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => $key === null ? '' : hash_hmac('sha256', $body, $key)], $body);
    }

    public function test_paytabs_opens_its_hosted_page_and_only_the_signed_callback_records_the_payment(): void
    {
        $this->paytabs();
        \Illuminate\Support\Facades\Http::fake(['secure-jordan.paytabs.com/payment/request' => \Illuminate\Support\Facades\Http::response(['tran_ref' => 'TST2611', 'redirect_url' => 'https://secure-jordan.paytabs.com/payment/wr/abc'], 200)]);
        $data = $this->start(['return_url' => 'https://school.test/parent/fees'])->assertCreated()->assertJsonPath('data.mode', 'redirect')->assertJsonPath('data.url', 'https://secure-jordan.paytabs.com/payment/wr/abc')->json('data');

        \Illuminate\Support\Facades\Http::assertSent(function ($r) use ($data) {
            return $r->hasHeader('authorization', 'SECRET-KEY') && $r['profile_id'] === '1234' && $r['cart_id'] === $data['reference'] && $r['cart_amount'] == 250.0 && $r['cart_currency'] === 'JOD'
                && str_contains($r['callback'], '/api/central/payments/webhook/') && str_ends_with($r['callback'], '/paytabs') && str_contains($r['return'], 'online='.$data['reference']);
        });
        $this->assertSame(0, Payment::count()); // the parent coming back changes nothing

        $this->callback($data['reference'], [], 'WRONG-KEY')->assertStatus(400);
        $this->callback($data['reference'], [], null)->assertStatus(400);
        $this->assertSame(0, Payment::count());
        $this->callback($data['reference'], ['cart_amount' => '10.00'])->assertStatus(400); // signed, but for less than was asked
        $this->assertSame(0, Payment::count());

        $this->callback($data['reference'])->assertOk();
        $this->assertSame(1, Payment::count());
        $this->callback($data['reference'])->assertOk(); // PayTabs retries: still one payment
        $this->assertSame(1, Payment::count());
        $this->assertSame('paid', OnlinePayment::where('reference', $data['reference'])->value('status'));
        $this->assertSame('TST2611', OnlinePayment::where('reference', $data['reference'])->value('provider_reference'));
    }

    public function test_a_declined_paytabs_payment_is_marked_failed_and_nothing_is_recorded(): void
    {
        $this->paytabs();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['tran_ref' => 'T1', 'redirect_url' => 'https://secure-jordan.paytabs.com/x'], 200)]);
        $ref = $this->start()->assertCreated()->json('data.reference');
        $this->callback($ref, ['payment_result' => ['response_status' => 'D', 'response_message' => 'Declined by the bank']])->assertOk();
        $online = OnlinePayment::where('reference', $ref)->first();
        $this->assertSame('failed', $online->status);
        $this->assertSame('Declined by the bank', $online->failure_reason);
        $this->assertSame(0, Payment::count());
    }

    public function test_when_paytabs_cannot_be_reached_the_parent_is_told_and_nothing_is_charged(): void
    {
        $this->paytabs();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['message' => 'Invalid profile'], 401)]);
        $this->start()->assertStatus(502);
        $this->assertSame('failed', OnlinePayment::query()->latest('id')->value('status'));
        $this->assertSame(0, Payment::count());
    }
}
