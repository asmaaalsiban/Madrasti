<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Models\AccountEntry;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Reconciliation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The cash boxes and bank accounts and their book. Every student payment lands in one of them (the one chosen, else the school's cash
 * box for cash and cheques and its bank account for the rest); money can be moved between accounts, corrected by hand, and matched
 * with the bank's statement. Amounts in an entry are signed: + into the account, - out of it.
 */
class MoneyBook
{
    /** Cash and cheques are kept in the cash box; transfers, cards and online payments reach the bank. */
    public const CASH_METHODS = ['cash', 'cheque'];

    /** The account a payment goes to: the chosen one (it must be active), else the first active account of the usual type for the method, else none. */
    public function accountFor(string $method, ?int $chosen = null): ?MoneyAccount
    {
        if ($chosen !== null) {
            $account = MoneyAccount::find($chosen);
            if ($account === null || ! $account->is_active) {
                throw ValidationException::withMessages(['money_account_id' => [__('finance.account_not_usable')]]);
            }

            return $account;
        }

        return MoneyAccount::where('is_active', true)->where('type', in_array($method, self::CASH_METHODS, true) ? 'cash' : 'bank')->orderBy('id')->first();
    }

    /** Puts a payment into the book of its account. */
    public function receive(Payment $payment, MoneyAccount $account): AccountEntry
    {
        $payment->update(['money_account_id' => $account->id]);

        return AccountEntry::create(['money_account_id' => $account->id, 'entry_date' => $payment->paid_on, 'kind' => AccountEntry::RECEIPT, 'amount' => $payment->amount, 'payment_id' => $payment->id, 'created_by' => $payment->received_by]);
    }

    /** A cancelled payment leaves the book, unless that line was already matched with the bank's statement. */
    public function release(Payment $payment): void
    {
        $entry = AccountEntry::where('payment_id', $payment->id)->first();
        if ($entry === null) {
            return;
        }
        if ($entry->reconciled_at !== null) {
            throw ValidationException::withMessages(['payment' => [__('finance.void_reconciled')]]);
        }
        $entry->delete();
    }

    /** Moves money from one account to another (e.g. the cash box to the bank): two lines that share a reference. */
    public function transfer(MoneyAccount $from, MoneyAccount $to, string|float $amount, string $date, ?string $note, User $by): void
    {
        $cents = Money::cents($amount);
        if ($from->id === $to->id || $cents <= 0 || ! $from->is_active || ! $to->is_active) {
            throw ValidationException::withMessages(['amount' => [__('finance.transfer_invalid')]]);
        }
        DB::transaction(function () use ($from, $to, $cents, $date, $note, $by): void {
            $ref = (string) Str::uuid();
            $base = ['entry_date' => $date, 'transfer_ref' => $ref, 'created_by' => $by->id];
            AccountEntry::create($base + ['money_account_id' => $from->id, 'kind' => AccountEntry::TRANSFER_OUT, 'amount' => Money::amount(-$cents), 'description' => $note ?: __('finance.transfer_to', ['name' => $to->name])]);
            AccountEntry::create($base + ['money_account_id' => $to->id, 'kind' => AccountEntry::TRANSFER_IN, 'amount' => Money::amount($cents), 'description' => $note ?: __('finance.transfer_from', ['name' => $from->name])]);
        });
    }

    /** A correction by hand (a difference found when counting the cash, a bank fee...): needs a reason. */
    public function adjust(MoneyAccount $account, string|float $signedAmount, string $date, string $note, User $by): AccountEntry
    {
        $cents = Money::cents($signedAmount);
        if ($cents === 0 || ! $account->is_active) {
            throw ValidationException::withMessages(['amount' => [__('finance.transfer_invalid')]]);
        }

        return AccountEntry::create(['money_account_id' => $account->id, 'entry_date' => $date, 'kind' => AccountEntry::ADJUSTMENT, 'amount' => Money::amount($cents), 'description' => $note, 'created_by' => $by->id]);
    }

    /**
     * The book of an account between two dates, with the running balance. The first line is the balance brought forward (the opening
     * balance plus everything before the first date).
     *
     * @return array{forward: string, rows: Collection<int, array<string, mixed>>, closing: string}
     */
    public function ledger(MoneyAccount $account, ?string $from, ?string $to): array
    {
        $forward = Money::cents($account->opening_balance) + ($from ? Money::cents((string) $account->entries()->whereDate('entry_date', '<', $from)->sum('amount')) : 0);
        $running = $forward;
        $rows = $account->entries()->with('payment:id,student_fee_id,payer_name')->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderBy('entry_date')->orderBy('id')->get()->map(function (AccountEntry $e) use (&$running): array {
                $running += Money::cents($e->amount);

                return ['id' => $e->id, 'date' => $e->entry_date->format('Y-m-d'), 'kind' => $e->kind, 'description' => $e->description ?? $e->payment?->payer_name, 'amount' => $e->amount, 'balance' => Money::amount($running), 'payment_id' => $e->payment_id, 'reconciled' => $e->reconciled_at !== null];
            });

        return ['forward' => Money::amount($forward), 'rows' => $rows->values(), 'closing' => Money::amount($running)];
    }

    /** What is not yet matched with the bank, and the balance of what is. @return array{cleared_balance: string, entries: Collection<int, array<string, mixed>>, last: ?array<string, mixed>} */
    public function toReconcile(MoneyAccount $account): array
    {
        $cleared = Money::cents($account->opening_balance) + Money::cents((string) $account->entries()->whereNotNull('reconciled_at')->sum('amount'));
        $last = Reconciliation::where('money_account_id', $account->id)->latest('statement_date')->latest('id')->first();

        return [
            'cleared_balance' => Money::amount($cleared),
            'entries' => $account->entries()->whereNull('reconciled_at')->orderBy('entry_date')->orderBy('id')->get()->map(fn (AccountEntry $e) => ['id' => $e->id, 'date' => $e->entry_date->format('Y-m-d'), 'kind' => $e->kind, 'description' => $e->description, 'amount' => $e->amount])->values(),
            'last' => $last ? ['statement_date' => $last->statement_date->format('Y-m-d'), 'statement_balance' => $last->statement_balance] : null,
        ];
    }

    /** Matches the chosen lines with the statement: allowed only when the cleared balance equals the statement's closing balance. */
    public function reconcile(MoneyAccount $account, string $statementDate, string|float $statementBalance, array $entryIds, User $by): Reconciliation
    {
        return DB::transaction(function () use ($account, $statementDate, $statementBalance, $entryIds, $by): Reconciliation {
            $chosen = $account->entries()->whereNull('reconciled_at')->whereIn('id', $entryIds)->lockForUpdate()->get();
            if ($chosen->count() !== count(array_unique($entryIds))) {
                throw ValidationException::withMessages(['entry_ids' => [__('finance.reconcile_bad_lines')]]);
            }
            $cleared = Money::cents($account->opening_balance) + Money::cents((string) $account->entries()->whereNotNull('reconciled_at')->sum('amount')) + $chosen->sum(fn (AccountEntry $e) => Money::cents($e->amount));
            $difference = Money::cents($statementBalance) - $cleared;
            if ($difference !== 0) {
                throw ValidationException::withMessages(['statement_balance' => [__('finance.reconcile_difference', ['difference' => Money::amount($difference)])]]);
            }
            $rec = Reconciliation::create(['money_account_id' => $account->id, 'statement_date' => $statementDate, 'statement_balance' => Money::amount(Money::cents($statementBalance)), 'created_by' => $by->id]);
            AccountEntry::whereIn('id', $chosen->pluck('id'))->update(['reconciled_at' => now(), 'reconciliation_id' => $rec->id]);

            return $rec;
        });
    }
}
