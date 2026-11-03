<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\OnlinePayment;
use App\Modules\Finance\Models\StudentFee;
use App\Shared\Payments\GatewayEvent;
use App\Shared\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Opens an online payment for a parent and, when the provider answers, turns it into a real Payment (once). */
class OnlinePayments
{
    public function __construct(private readonly FeeBilling $billing, private readonly PaymentManager $manager, private readonly Notifier $notifier) {}

    /** Creates the pending payment. The amount can never be more than what is owed (on the installment, when one is chosen). */
    public function open(Student $student, User $parent, StudentFee $fee, float|string $amount, ?int $installmentId, string $payerName, ?string $provider = null): OnlinePayment
    {
        abort_unless($fee->student_id === $student->id, 404);
        $cents = Money::cents($amount);
        $limit = $this->billing->remainingCents($fee);
        if ($installmentId !== null) {
            $installment = $fee->installments()->whereKey($installmentId)->firstOrFail();
            $limit = min($limit, $installment->remainingCents());
        }
        if ($cents <= 0 || $cents > $limit) {
            throw ValidationException::withMessages(['amount' => [__('finance.more_than_owed', ['owed' => Money::amount($limit)])]]);
        }

        return OnlinePayment::create([
            'reference' => (string) Str::uuid(), 'student_id' => $student->id, 'student_fee_id' => $fee->id, 'installment_id' => $installmentId, 'user_id' => $parent->id,
            'amount' => Money::amount($cents), 'payer_name' => $payerName, 'provider' => $provider ?? $this->manager->driverName(), 'status' => OnlinePayment::PENDING,
        ]);
    }

    /**
     * Applies what the provider said. Safe to call again (providers retry webhooks): only a pending payment changes.
     * A paid one becomes a Payment with method "online" in the payer's name; if the fee can no longer take it (paid
     * another way meanwhile) it is kept as "review" so a person can refund it.
     */
    public function settle(OnlinePayment $online, GatewayEvent $event): OnlinePayment
    {
        $notify = false;
        $online = DB::transaction(function () use ($online, $event, &$notify): OnlinePayment {
            $row = OnlinePayment::whereKey($online->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== OnlinePayment::PENDING) {
                return $row;
            }
            $row->provider_reference = $event->providerReference ?? $row->provider_reference;
            if (! $event->paid) {
                $row->update(['status' => OnlinePayment::FAILED, 'failure_reason' => $event->failureReason]);

                return $row;
            }
            try {
                $payment = $this->billing->recordPayment(
                    $row->fee, $row->amount, $row->provider === 'bank_transfer' ? 'bank_transfer' : 'online', now()->toDateString(), User::findOrFail($row->user_id),
                    $row->provider_reference ?? $row->reference, __('finance.online_note'), $row->installment_id, $row->payer_name,
                );
                $row->update(['status' => OnlinePayment::PAID, 'payment_id' => $payment->id]);
                $notify = true;
            } catch (ValidationException) {
                $row->update(['status' => OnlinePayment::REVIEW, 'failure_reason' => 'not_applicable']);
            }

            return $row;
        });

        if ($notify) {
            $student = Student::findOrFail($online->student_id);
            $this->notifier->toUsers([$online->user_id], 'online_payment', [
                'amount' => Money::amount(Money::cents($online->amount)), 'child_ar' => $student->name_ar, 'child_en' => $student->name_en,
            ], "/parent/children/{$student->id}/fees");
        }

        return $online;
    }
}
