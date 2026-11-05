<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\OnlinePayment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Finance\Services\OnlinePayments;
use App\Modules\Communication\Services\Notifier;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use App\Shared\Payments\DemoPaymentGateway;
use App\Shared\Payments\InvalidWebhook;
use App\Shared\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/** A parent pays a child's fee online. Money moves only when the provider says so (webhook), never on the parent's word. */
class OnlinePaymentController extends Controller
{
    public function __construct(private readonly OnlinePayments $online, private readonly PaymentManager $manager, private readonly Notifier $notifier) {}

    /** Starts a payment: returns how to continue (the demo card form, or the provider's page to go to). */
    public function start(Request $request, Student $student): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Guardian), 403);
        Gate::authorize('view-student-fees', $student);
        $method = $request->input('method', 'card');
        abort_unless(in_array($method, array_column($this->manager->methods(), 'key'), true), 404);

        $data = $request->validate([
            'method' => ['sometimes', 'in:card,bank_transfer'],
            'transfer_reference' => [$method === 'bank_transfer' ? 'required' : 'nullable', 'string', 'max:100'],
            'student_fee_id' => ['required', 'integer', 'exists:student_fees,id'],
            'installment_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payer_name' => ['required', 'string', 'max:255'],
            'return_url' => ['nullable', 'url', 'max:500'], // where a provider's page sends the parent back
        ]);
        if ($method === 'bank_transfer') {
            return $this->submitTransfer($student, $user, $data);
        }
        $payment = $this->online->open($student, $user, StudentFee::findOrFail($data['student_fee_id']), $data['amount'], $data['installment_id'] ?? null, $data['payer_name']);
        try {
            $checkout = $this->manager->gateway()->start($payment, ($data['return_url'] ?? '').(str_contains($data['return_url'] ?? '', '?') ? '&' : '?').'online='.$payment->reference);
        } catch (\Throwable $e) {
            // The provider could not open the payment (down, wrong keys): nothing was charged, and the parent is told to try again.
            Log::error('The payment provider could not open a payment: '.$e->getMessage());
            $payment->update(['status' => OnlinePayment::FAILED, 'failure_reason' => 'provider_unreachable']);
            abort(502, __('finance.gateway_unreachable'));
        }
        if ($checkout->providerReference) {
            $payment->update(['provider_reference' => $checkout->providerReference]);
        }

        return response()->json(['data' => ['reference' => $payment->reference, 'mode' => $checkout->mode, 'url' => $checkout->url]], 201);
    }

    /**
     * A bank transfer / CliQ: nothing is paid yet. The parent says what they transferred (and sends the proof, an image or PDF, attached to
     * this payment); the accountant confirms it, and only then the payment is recorded. The finance people are told.
     *
     * @param  array<string, mixed>  $data
     */
    private function submitTransfer(Student $student, User $user, array $data): JsonResponse
    {
        $payment = $this->online->open($student, $user, StudentFee::findOrFail($data['student_fee_id']), $data['amount'], $data['installment_id'] ?? null, $data['payer_name'], 'bank_transfer');
        $payment->update(['transfer_reference' => $data['transfer_reference']]);
        $finance = User::where('is_active', true)->where(fn ($q) => $q->where('role', Role::Admin)->orWhere(fn ($t) => $t->where('role', Role::Teacher)->whereHas('staffProfile', fn ($p) => $p->where('staff_type', StaffType::Accountant->value))))->pluck('id');
        $this->notifier->toUsers($finance, 'transfer_submitted', ['name' => $data['payer_name'], 'amount' => $payment->amount, 'child_ar' => $student->name_ar, 'child_en' => $student->name_en], '/teacher/finance/transfers');

        return response()->json(['data' => ['id' => $payment->id, 'reference' => $payment->reference, 'mode' => 'transfer', 'url' => null]], 201);
    }

    /** The demo card form: judges the typed card. The card is never stored or logged. */
    public function demoConfirm(Request $request, string $reference): JsonResponse
    {
        $payment = $this->mine($request, $reference);
        abort_unless($this->manager->driverName() === 'demo', 404);
        $data = $request->validate(['card_number' => ['required', 'string', 'max:32'], 'expiry' => ['required', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'], 'cvc' => ['required', 'digits_between:3,4'], 'holder' => ['required', 'string', 'max:255']]);
        [$month, $year] = array_map('intval', explode('/', $data['expiry']));
        if (now()->format('ym') > sprintf('%02d%02d', $year, $month)) {
            return response()->json(['message' => __('finance.card_expired'), 'errors' => ['expiry' => [__('finance.card_expired')]]], 422);
        }

        /** @var DemoPaymentGateway $gateway */
        $gateway = $this->manager->gateway('demo');

        return response()->json(['data' => $this->show($this->online->settle($payment, $gateway->charge($payment, $data['card_number'])))]);
    }

    /** Where a payment stands: the page the parent returns to polls this. */
    public function status(Request $request, string $reference): JsonResponse
    {
        return response()->json(['data' => $this->show($this->mine($request, $reference))]);
    }

    /** A provider's webhook (public, no login): trusted only after the driver verifies the provider's signature. */
    public function webhook(Request $request, string $provider): JsonResponse
    {
        $gateway = $this->manager->gateway($provider);
        try {
            $event = $gateway->parseWebhook($request);
        } catch (InvalidWebhook $e) {
            Log::warning('Rejected a payment webhook from '.$provider.': '.$e->getMessage());
            abort(400);
        }
        $payment = OnlinePayment::where('reference', $event->reference)->where('provider', $provider)->first();
        if ($payment) {
            $this->online->settle($payment, $event);
        }

        return response()->json(['ok' => true]);
    }

    private function mine(Request $request, string $reference): OnlinePayment
    {
        return OnlinePayment::where('reference', $reference)->where('user_id', $request->user()->id)->with('payment')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function show(OnlinePayment $p): array
    {
        $p->loadMissing('payment');

        return ['reference' => $p->reference, 'provider' => $p->provider, 'status' => $p->status, 'amount' => $p->amount, 'failure_reason' => $p->failure_reason, 'review_note' => $p->review_note, 'receipt' => $p->payment?->receiptNumber()];
    }
}
