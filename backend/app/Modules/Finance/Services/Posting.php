<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\AccountEntry;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\LedgerAccount;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Models\StudentFee;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the journal in step with the school's money events, by itself.
 *
 * Every event says what its lines SHOULD be in total (`sync`). The service compares that with what is already posted for the
 * same source and posts only the difference. So a new fee, an edited bill and a cancelled payment all work the same way, the
 * result is always balanced, and running it twice changes nothing.
 */
class Posting
{
    public const CASH = '1000';

    public const BANK = '1100';

    public const TRANSIT = '1190';

    public const RECEIVABLE = '1200';

    public const PAYABLE = '2000';

    public const EQUITY = '3000';

    public const TUITION = '4000';

    public const OTHER_INCOME = '4100';

    public const SALARIES = '5000';

    public const OTHER_EXPENSE = '5500';

    private const BILL_ACCOUNTS = ['Salaries' => '5000', 'Rent' => '5100', 'Utilities' => '5200', 'Maintenance' => '5300', 'Supplies' => '5400'];

    public function __construct(private readonly Journal $journal) {}

    /**
     * @param  array<string, int>  $net  ledger code => cents, debit positive and credit negative (must add up to zero)
     */
    public function sync(string $type, int $id, string $date, string $memo, array $net): void
    {
        $net = array_filter($net, fn (int $c) => $c !== 0);
        $posted = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.source_type', $type)->where('e.source_id', $id)
            ->selectRaw('l.ledger_account_id as account, sum(l.debit) as d, sum(l.credit) as c')->groupBy('l.ledger_account_id')->get();
        $codes = LedgerAccount::pluck('id', 'code');
        $diff = [];
        foreach ($net as $code => $cents) {
            $diff[$codes[$code]] = $cents;
        }
        foreach ($posted as $row) {
            $diff[$row->account] = ($diff[$row->account] ?? 0) - (Money::cents($row->d) - Money::cents($row->c));
        }
        $lines = [];
        foreach (array_filter($diff) as $account => $cents) {
            $lines[] = ['ledger_account_id' => (int) $account, 'debit' => $cents > 0 ? Money::amount($cents) : 0, 'credit' => $cents < 0 ? Money::amount(-$cents) : 0];
        }
        if ($lines === []) {
            return;
        }
        $locked = $this->journal->lockedUntil();
        $when = $locked && $date <= $locked ? date('Y-m-d', strtotime($locked.' +1 day')) : $date; // a locked day is never touched: the change lands on the first open day
        $this->journal->post($when, $memo, $lines, null, $type, $id);
    }

    public function fee(StudentFee $fee): void
    {
        $net = Money::cents($fee->total_amount) - Money::cents($fee->discount);
        $carried = $fee->kind === 'carried'; // moved from last year: the debt is already on the books
        $this->sync('fee', $fee->id, ($fee->created_at ?? now())->toDateString(), __('finance.auto.fee', ['name' => $fee->description]), $carried ? [] : [self::RECEIVABLE => $net, self::TUITION => -$net]);
    }

    public function bill(Expense $bill): void
    {
        $bill->loadMissing('category');
        $cents = Money::cents($bill->amount);
        $this->sync('bill', $bill->id, $bill->expense_date->toDateString(), __('finance.auto.bill', ['name' => $bill->description]), [(self::BILL_ACCOUNTS[$bill->category?->name_en] ?? self::OTHER_EXPENSE) => $cents, self::PAYABLE => -$cents]);
    }

    public function opening(MoneyAccount $account): void
    {
        $cents = Money::cents($account->opening_balance);
        $this->sync('opening', $account->id, $account->opened_on?->toDateString() ?? now()->toDateString(), __('finance.auto.opening', ['name' => $account->name]), [$this->cashCode($account) => $cents, self::EQUITY => -$cents]);
    }

    /** A line of an account's book. `$gone` is true once the line was cancelled. */
    public function entry(AccountEntry $e, bool $gone = false): void
    {
        $cents = $gone ? 0 : Money::cents($e->amount);
        $cash = $this->cashCode($e->account ?? MoneyAccount::find($e->money_account_id));
        $other = match ($e->kind) {
            AccountEntry::RECEIPT => self::RECEIVABLE,
            AccountEntry::TRANSFER_IN, AccountEntry::TRANSFER_OUT => self::TRANSIT,
            AccountEntry::EXPENSE => self::PAYABLE,
            AccountEntry::PAYROLL => self::SALARIES,
            default => $cents >= 0 ? self::OTHER_INCOME : self::OTHER_EXPENSE,
        };
        $this->sync('entry', $e->id, $e->entry_date->toDateString(), __('finance.auto.entry', ['name' => $e->description ?: __('finance.auto.kinds.'.$e->kind)]), [$cash => $cents, $other => -$cents]);
    }

    /** Posts everything that is missing (a school that had money events before the journal existed). */
    public function rebuildAll(): void
    {
        MoneyAccount::each(fn (MoneyAccount $a) => $this->opening($a));
        StudentFee::each(fn (StudentFee $f) => $this->fee($f));
        Expense::with('category')->each(fn (Expense $b) => $this->bill($b));
        AccountEntry::each(fn (AccountEntry $e) => $this->entry($e));
    }

    private function cashCode(?MoneyAccount $a): string
    {
        return $a?->type === 'bank' ? self::BANK : self::CASH;
    }
}
