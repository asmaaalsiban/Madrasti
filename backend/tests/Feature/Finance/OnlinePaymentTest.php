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
        $this->getJson("/api/students/{$this->child->id}/fees")->assertOk()->assertJsonPath('data.online.enabled', true)->assertJsonPath('data.online.mode', 'demo')->assertJsonPath('data.online.methods', ['card']);

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

    private function paytabs(): void
    {
        config(['payments.driver' => 'paytabs', 'payments.providers.paytabs' => ['profile_id' => '1234', 'server_key' => 'SECRET-KEY', 'base_url' => 'https://secure-jordan.paytabs.com']]);
    }

    private function signedCallback(string $reference, array $over = [], ?string $key = 'SECRET-KEY')
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

        $this->signedCallback($data['reference'], [], 'WRONG-KEY')->assertStatus(400);
        $this->signedCallback($data['reference'], [], null)->assertStatus(400);
        $this->assertSame(0, Payment::count());
        $this->signedCallback($data['reference'], ['cart_amount' => '10.00'])->assertStatus(400); // signed, but for less than was asked
        $this->assertSame(0, Payment::count());

        $this->signedCallback($data['reference'])->assertOk();
        $this->assertSame(1, Payment::count());
        $this->signedCallback($data['reference'])->assertOk(); // PayTabs retries: still one payment
        $this->assertSame(1, Payment::count());
        $this->assertSame('paid', OnlinePayment::where('reference', $data['reference'])->value('status'));
        $this->assertSame('TST2611', OnlinePayment::where('reference', $data['reference'])->value('provider_reference'));
    }

    public function test_a_declined_paytabs_payment_is_marked_failed_and_nothing_is_recorded(): void
    {
        $this->paytabs();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['tran_ref' => 'T1', 'redirect_url' => 'https://secure-jordan.paytabs.com/x'], 200)]);
        $ref = $this->start()->assertCreated()->json('data.reference');
        $this->signedCallback($ref, ['payment_result' => ['response_status' => 'D', 'response_message' => 'Declined by the bank']])->assertOk();
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

    private function school(array $extra = []): void
    {
        $principal = User::factory()->role(Role::Admin)->create();
        Sanctum::actingAs($principal);
        $this->putJson('/api/finance/payment-settings', ['profile_id' => '777', 'server_key' => 'SCHOOL-KEY-9876', 'base_url' => 'https://secure.paytabs.sa', 'enabled' => true, ...$extra])->assertOk();
        Sanctum::actingAs($this->parent);
    }

    public function test_the_principal_saves_the_schools_own_paytabs_keys_and_the_server_key_is_never_shown_again(): void
    {
        $this->school();
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->getJson('/api/finance/payment-settings')->assertOk()->assertJsonPath('data.profile_id', '777')->assertJsonPath('data.server_key_set', true)->assertJsonPath('data.server_key_hint', '••••9876')
            ->assertJsonPath('data.enabled', true)->assertJsonMissingPath('data.server_key');
        $this->assertStringNotContainsString('SCHOOL-KEY-9876', (string) DB::table('school_settings')->where('key', 'paytabs')->value('value')); // stored encrypted
        $this->putJson('/api/finance/payment-settings', ['profile_id' => '888', 'server_key' => '', 'base_url' => 'https://secure.paytabs.sa', 'enabled' => true])->assertOk()->assertJsonPath('data.server_key_hint', '••••9876'); // an empty key keeps the saved one
        $this->putJson('/api/finance/payment-settings', ['profile_id' => '888', 'base_url' => 'https://evil.example', 'enabled' => true])->assertStatus(422);
    }

    public function test_only_the_principal_and_the_accountant_set_the_payment_keys(): void
    {
        Sanctum::actingAs($this->parent);
        $this->getJson('/api/finance/payment-settings')->assertForbidden();
        Sanctum::actingAs(User::factory()->staff(\App\Shared\Enums\StaffType::Teacher)->create());
        $this->putJson('/api/finance/payment-settings', ['profile_id' => '1', 'server_key' => 'k', 'base_url' => 'https://secure.paytabs.sa', 'enabled' => true])->assertForbidden();
        Sanctum::actingAs(User::factory()->staff(\App\Shared\Enums\StaffType::Accountant)->create());
        $this->putJson('/api/finance/payment-settings', ['profile_id' => '1', 'server_key' => 'k', 'base_url' => 'https://secure.paytabs.sa', 'enabled' => true])->assertOk();
    }

    public function test_a_school_with_its_own_account_pays_through_it_whatever_the_env_says_and_its_callback_uses_its_own_key(): void
    {
        config(['payments.driver' => 'demo']); // .env says demo, the school has its own account
        $this->school();
        \Illuminate\Support\Facades\Http::fake(['secure.paytabs.sa/payment/request' => \Illuminate\Support\Facades\Http::response(['tran_ref' => 'S1', 'redirect_url' => 'https://secure.paytabs.sa/pay/x'], 200)]);
        $ref = $this->start()->assertCreated()->assertJsonPath('data.mode', 'redirect')->json('data.reference');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->hasHeader('authorization', 'SCHOOL-KEY-9876') && $r['profile_id'] === '777' && str_starts_with($r->url(), 'https://secure.paytabs.sa/'));

        $this->signedCallback($ref, [], 'SECRET-KEY')->assertStatus(400); // signed with some other key
        $this->signedCallback($ref, [], 'SCHOOL-KEY-9876')->assertOk();
        $this->assertSame('paid', OnlinePayment::where('reference', $ref)->value('status'));
    }

    public function test_switching_the_schools_account_off_goes_back_to_the_platform_setting_and_the_connection_can_be_tested(): void
    {
        config(['payments.driver' => 'none']);
        $this->school(['enabled' => false]);
        $this->assertNull(app(PaymentManager::class)->driverName());

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        \Illuminate\Support\Facades\Http::fake(['secure.paytabs.sa/*' => \Illuminate\Support\Facades\Http::sequence()->push(['redirect_url' => 'https://secure.paytabs.sa/pay/t'], 200)->push(['message' => 'Invalid profile'], 401)]);
        $this->postJson('/api/finance/payment-settings/test')->assertOk()->assertJsonPath('ok', true);
        $this->postJson('/api/finance/payment-settings/test')->assertOk()->assertJsonPath('ok', false)->assertJsonPath('message', 'Invalid profile');
    }

    private function bank(array $extra = []): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->putJson('/api/finance/bank-transfer-settings', ['enabled' => true, 'instructions' => 'Write the child name in the note', 'accounts' => [['bank' => 'Arab Bank', 'holder' => 'Demo School', 'iban' => 'JO94CBJO0010000000000131000302', 'cliq' => 'DEMOSCHOOL'], ...$extra]])->assertOk();
        Sanctum::actingAs($this->parent);
    }

    private function transfer(array $extra = [])
    {
        return $this->postJson("/api/students/{$this->child->id}/online-payments", ['method' => 'bank_transfer', 'student_fee_id' => $this->fee->id, 'amount' => 250, 'payer_name' => 'Abu Ali', 'transfer_reference' => 'CLQ-889', ...$extra]);
    }

    public function test_a_school_can_offer_card_and_bank_transfer_together_and_the_parent_chooses(): void
    {
        config(['payments.driver' => 'demo']);
        $this->getJson("/api/students/{$this->child->id}/fees")->assertJsonPath('data.online.methods', ['card'])->assertJsonPath('data.online.bank', null);
        $this->bank();
        $this->getJson("/api/students/{$this->child->id}/fees")->assertJsonPath('data.online.methods', ['card', 'bank_transfer'])->assertJsonPath('data.online.bank.accounts.0.cliq', 'DEMOSCHOOL')->assertJsonPath('data.online.enabled', true);
        config(['payments.driver' => 'none']); // no card gateway: only the transfer is left, the school is never forced to take cards
        $this->getJson("/api/students/{$this->child->id}/fees")->assertJsonPath('data.online.methods', ['bank_transfer']);
    }

    public function test_a_transfer_waits_for_the_accountant_who_confirms_it_and_the_payment_is_recorded_as_a_bank_transfer(): void
    {
        $this->bank();
        $accountant = User::factory()->staff(\App\Shared\Enums\StaffType::Accountant)->create();
        $id = $this->transfer()->assertCreated()->assertJsonPath('data.mode', 'transfer')->json('data.id');
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $accountant->id)->where('data', 'like', '%transfer_submitted%')->count());

        // The proof is attached by the payer; the finance people see it, a stranger does not.
        \Illuminate\Support\Facades\Storage::fake();
        $file = \Illuminate\Http\UploadedFile::fake()->image('proof.jpg');
        $this->postJson("/api/attachments/online_payment/$id", ['files' => [$file]])->assertCreated();
        $this->getJson("/api/students/{$this->child->id}/fees")->assertJsonPath('data.online.transfers.0.status', 'pending');

        Sanctum::actingAs($accountant);
        $row = $this->getJson('/api/finance/transfers')->assertOk()->assertJsonPath('pending', 1)->assertJsonPath('data.0.transfer_reference', 'CLQ-889')->assertJsonPath('data.0.attachments.0.name', 'proof.jpg')->json('data.0');
        $this->get('/api/attachments/'.$row['attachments'][0]['id'])->assertOk();
        $this->postJson("/api/finance/transfers/$id/approve")->assertOk()->assertJsonPath('data.status', 'paid');
        $payment = Payment::firstOrFail();
        $this->assertSame('bank_transfer', $payment->method);
        $this->assertSame('250.00', $payment->amount);
        $this->postJson("/api/finance/transfers/$id/approve")->assertStatus(422); // decided once
        $this->assertSame(1, Payment::count());
        Sanctum::actingAs($this->parent);
        $this->postJson("/api/attachments/online_payment/$id", ['files' => [$file]])->assertForbidden(); // too late to add proof
    }

    public function test_a_refused_transfer_tells_the_parent_why_and_pays_nothing(): void
    {
        $this->bank();
        $id = $this->transfer()->json('data.id');
        $accountant = User::factory()->staff(\App\Shared\Enums\StaffType::Accountant)->create();
        Sanctum::actingAs($accountant);
        $this->postJson("/api/finance/transfers/$id/reject", [])->assertStatus(422); // a reason is needed
        $this->postJson("/api/finance/transfers/$id/reject", ['note' => 'The money did not arrive'])->assertOk();
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->parent->id)->where('data', 'like', '%transfer_rejected%')->count());
        Sanctum::actingAs($this->parent);
        $this->getJson("/api/students/{$this->child->id}/fees")->assertJsonPath('data.online.transfers.0.status', 'failed')->assertJsonPath('data.online.transfers.0.review_note', 'The money did not arrive');
    }

    public function test_the_transfer_needs_a_reference_a_valid_amount_and_the_way_must_be_switched_on(): void
    {
        $this->transfer()->assertNotFound(); // not offered yet
        $this->bank();
        $this->transfer(['transfer_reference' => ''])->assertStatus(422)->assertJsonValidationErrors('transfer_reference');
        $this->transfer(['amount' => 99999])->assertStatus(422);
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->putJson('/api/finance/bank-transfer-settings', ['enabled' => true, 'accounts' => []])->assertStatus(422);
        Sanctum::actingAs($this->parent);
        $this->getJson('/api/finance/transfers')->assertForbidden();
        $this->putJson('/api/finance/bank-transfer-settings', ['enabled' => false, 'accounts' => []])->assertForbidden();
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
}
