<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Models\AccountEntry;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpensePayment;
use App\Modules\Finance\Models\Money;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Procurement\Models\ProcurementRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the school spends. A bill (expense) is recorded for a supplier and a category; it is paid in one or more payments, each out
 * of an account (the cash box or a bank), and each payment has its own line in that account's book. A bill can be recorded already paid.
 */
class Expenses
{
    public function __construct(private readonly MoneyBook $book) {}

    /**
     * @param  array<string, mixed>  $data  the bill
     * @param  array<string, mixed>|null  $payNow  money_account_id, method, paid_on, reference, note: pay the whole bill at once
     */
    public function create(array $data, User $by, ?array $payNow = null): Expense
    {
        return DB::transaction(function () use ($data, $by, $payNow): Expense {
            $expense = Expense::create($data + ['created_by' => $by->id]);
            $this->buyRequest($expense, $by);
            if ($payNow !== null) {
                $this->pay($expense, $expense->amount, (int) $payNow['money_account_id'], $payNow['method'], $payNow['paid_on'] ?? $expense->expense_date->format('Y-m-d'), $payNow['reference'] ?? null, $payNow['note'] ?? null, $by);
            }

            return $expense->refresh()->load('payments');
        });
    }

    /** A purchase request the principal approved is now bought: the bill is the purchase (supplier, real cost, invoice number), and the requester is told, as when the accountant records it on the board. */
    private function buyRequest(Expense $expense, User $by): void
    {
        $request = $expense->procurement_request_id ? ProcurementRequest::where('id', $expense->procurement_request_id)->where('status', 'approved')->first() : null;
        if ($request === null) {
            return;
        }
        $request->update(['status' => 'purchased', 'supplier' => $expense->supplier?->name ?? $expense->description, 'actual_cost' => $expense->amount, 'purchase_ref' => $expense->reference, 'purchased_by' => $by->id, 'purchased_at' => now()]);
        app(Notifier::class)->toUsers([$request->requested_by], 'procurement_purchased', ['title' => $request->title], '/teacher/procurement');
    }

    public function pay(Expense $expense, string|float $amount, int $accountId, string $method, string $paidOn, ?string $reference, ?string $note, User $by): ExpensePayment
    {
        return DB::transaction(function () use ($expense, $amount, $accountId, $method, $paidOn, $reference, $note, $by): ExpensePayment {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail()->load('payments');
            $cents = Money::cents($amount);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => [__('finance.bad_amounts')]]);
            }
            if ($cents > $expense->remainingCents()) {
                throw ValidationException::withMessages(['amount' => [__('finance.expense_more_than_owed', ['owed' => Money::amount($expense->remainingCents())])]]);
            }
            $account = MoneyAccount::find($accountId);
            if ($account === null || ! $account->is_active) {
                throw ValidationException::withMessages(['money_account_id' => [__('finance.account_not_usable')]]);
            }
            $payment = ExpensePayment::create(['expense_id' => $expense->id, 'money_account_id' => $account->id, 'amount' => Money::amount($cents), 'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference, 'note' => $note, 'created_by' => $by->id]);
            $this->book->spend($account, Money::amount($cents), $paidOn, AccountEntry::EXPENSE, $expense->description, 'expense_payment', $payment->id, $by);

            return $payment;
        });
    }

    /** Cancels a payment made by mistake: the money goes back into the account's book (unless that line was already matched with the bank). */
    public function removePayment(ExpensePayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $this->book->releaseSource('expense_payment', $payment->id);
            $payment->delete();
        });
    }

    /** A bill cannot be made smaller than what was already paid on it. */
    public function update(Expense $expense, array $data): Expense
    {
        if (isset($data['amount']) && Money::cents($data['amount']) < $expense->paidCents()) {
            throw ValidationException::withMessages(['amount' => [__('finance.expense_below_paid', ['paid' => Money::amount($expense->paidCents())])]]);
        }
        $expense->update($data);

        return $expense->refresh()->load('payments');
    }

    /** Only a bill nothing was paid on can be deleted. */
    public function delete(Expense $expense): void
    {
        if ($expense->payments()->exists()) {
            throw ValidationException::withMessages(['expense' => [__('finance.expense_has_payments')]]);
        }
        $expense->delete();
    }
}
