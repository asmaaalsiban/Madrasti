<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\LedgerAccount;
use App\Modules\Finance\Models\Money;
use Illuminate\Support\Facades\DB;

/**
 * The accounting reports, all read from the journal. Every report is `{columns, rows, totals}`: the column keys are
 * translated by the page, the rows are plain values.
 */
class AccountingReports
{
    private function name(object $a): string
    {
        return app()->getLocale() === 'ar' ? $a->name_ar : $a->name_en;
    }

    /** @return \Illuminate\Support\Collection<int, object> one row per account with the sums of its lines inside the dates */
    private function sums(?string $from, ?string $to)
    {
        return DB::table('ledger_accounts as a')
            ->leftJoin('journal_lines as l', 'l.ledger_account_id', '=', 'a.id')
            ->leftJoin('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.entry_date', '<=', $to))
            ->groupBy('a.id', 'a.code', 'a.name_ar', 'a.name_en', 'a.type')->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name_ar, a.name_en, a.type, coalesce(sum(l.debit),0) as d, coalesce(sum(l.credit),0) as c')->get();
    }

    /** The balance of an account in its natural direction: debit for assets and expenses, credit for the rest. */
    private function natural(object $r): int
    {
        $net = Money::cents($r->d) - Money::cents($r->c);

        return in_array($r->type, ['asset', 'expense'], true) ? $net : -$net;
    }

    /** @return array<string, mixed> */
    public function trialBalance(string $to): array
    {
        $rows = [];
        $debit = 0;
        $credit = 0;
        foreach ($this->sums(null, $to) as $r) {
            $net = Money::cents($r->d) - Money::cents($r->c);
            if ($net === 0 && Money::cents($r->d) === 0) {
                continue;
            }
            $dr = max($net, 0);
            $cr = max(-$net, 0);
            $debit += $dr;
            $credit += $cr;
            $rows[] = [$r->code, $this->name($r), Money::amount($dr), Money::amount($cr)];
        }

        return ['columns' => ['code', 'account', 'debit', 'credit'], 'rows' => $rows, 'totals' => ['debit' => Money::amount($debit), 'credit' => Money::amount($credit)], 'balanced' => $debit === $credit];
    }

    /** @return array<string, mixed> */
    public function incomeStatement(string $from, string $to): array
    {
        $rows = [];
        $income = 0;
        $expense = 0;
        foreach ($this->sums($from, $to) as $r) {
            if (! in_array($r->type, ['income', 'expense'], true) || ($r->d == 0 && $r->c == 0)) {
                continue;
            }
            $v = $this->natural($r);
            $r->type === 'income' ? $income += $v : $expense += $v;
            $rows[] = [$r->type, $r->code, $this->name($r), Money::amount($v)];
        }

        return ['columns' => ['type', 'code', 'account', 'amount'], 'rows' => $rows, 'totals' => ['income' => Money::amount($income), 'expense' => Money::amount($expense), 'profit' => Money::amount($income - $expense)]];
    }

    /** @return array<string, mixed> */
    public function balanceSheet(string $asOf): array
    {
        $rows = [];
        $sum = ['asset' => 0, 'liability' => 0, 'equity' => 0];
        $earnings = 0;
        foreach ($this->sums(null, $asOf) as $r) {
            $v = $this->natural($r);
            if ($r->type === 'income') {
                $earnings += $v;
            } elseif ($r->type === 'expense') {
                $earnings -= $v;
            } elseif ($v !== 0) {
                $sum[$r->type] += $v;
                $rows[] = [$r->type, $r->code, $this->name($r), Money::amount($v)];
            }
        }
        $sum['equity'] += $earnings; // what the school earned so far is part of its equity
        if ($earnings !== 0) {
            $rows[] = ['equity', '', null, Money::amount($earnings)];
        }

        return ['columns' => ['type', 'code', 'account', 'amount'], 'rows' => $rows, 'totals' => ['assets' => Money::amount($sum['asset']), 'liabilities' => Money::amount($sum['liability']), 'equity' => Money::amount($sum['equity'])], 'balanced' => $sum['asset'] === $sum['liability'] + $sum['equity']];
    }

    /** @return array<string, mixed> */
    public function accountLedger(LedgerAccount $account, ?string $from, ?string $to): array
    {
        $sign = in_array($account->type, ['asset', 'expense'], true) ? 1 : -1;
        $forward = 0;
        if ($from) {
            $f = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.ledger_account_id', $account->id)->where('e.entry_date', '<', $from)->selectRaw('coalesce(sum(l.debit),0) as d, coalesce(sum(l.credit),0) as c')->first();
            $forward = $sign * (Money::cents($f->d) - Money::cents($f->c));
        }
        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.ledger_account_id', $account->id)
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from))->when($to, fn ($q) => $q->where('e.entry_date', '<=', $to))
            ->orderBy('e.entry_date')->orderBy('e.id')->get(['e.entry_date', 'e.memo', 'l.debit', 'l.credit']);
        $running = $forward;
        $rows = [];
        foreach ($lines as $l) {
            $running += $sign * (Money::cents($l->debit) - Money::cents($l->credit));
            $rows[] = [$l->entry_date, $l->memo, Money::amount(Money::cents($l->debit)), Money::amount(Money::cents($l->credit)), Money::amount($running)];
        }

        return ['columns' => ['date', 'memo', 'debit', 'credit', 'balance'], 'rows' => $rows, 'totals' => ['forward' => Money::amount($forward), 'closing' => Money::amount($running)], 'account' => ['code' => $account->code, 'name' => $this->name($account)]];
    }

    /**
     * What each student still owes, split by how late it is on `$asOf`.
     *
     * @return array<string, mixed>
     */
    public function receivablesAgeing(string $asOf): array
    {
        $buckets = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'over90' => 0];
        $byStudent = [];
        $installments = Installment::with('fee.student')->whereColumn('paid_amount', '<', 'amount')->get();
        foreach ($installments as $i) {
            $fee = $i->fee;
            if ($fee === null || $fee->student === null) {
                continue;
            }
            $owed = Money::cents($i->amount) - Money::cents($i->paid_amount);
            $late = (int) floor((strtotime($asOf) - strtotime($i->due_date->toDateString())) / 86400);
            $key = $late <= 0 ? 'current' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : ($late <= 90 ? 'd90' : 'over90')));
            $byStudent[$fee->student_id] ??= ['name' => app()->getLocale() === 'ar' ? $fee->student->name_ar : $fee->student->name_en] + $buckets;
            $byStudent[$fee->student_id][$key] += $owed;
            $buckets[$key] += $owed;
        }
        $rows = collect($byStudent)->sortByDesc(fn ($r) => array_sum(array_slice($r, 1)))->map(fn ($r) => [$r['name'], ...array_map(fn ($k) => Money::amount($r[$k]), array_keys($buckets)), Money::amount(array_sum(array_slice($r, 1)))])->values()->all();

        return ['columns' => ['student', 'current', 'd30', 'd60', 'd90', 'over90', 'total'], 'rows' => $rows, 'totals' => array_map(fn ($c) => Money::amount($c), $buckets) + ['total' => Money::amount(array_sum($buckets))]];
    }

    /**
     * Tax collected on sales (output), tax paid on purchases (input) and the difference, month by month.
     *
     * @return array<string, mixed>
     */
    public function taxSummary(string $from, string $to): array
    {
        $ids = DB::table('ledger_accounts')->whereIn('code', [Posting::TAX_PAYABLE, Posting::INPUT_TAX])->pluck('id', 'code');
        $months = [];
        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->whereIn('l.ledger_account_id', $ids->values())
            ->whereBetween('e.entry_date', [$from, $to])->get(['e.entry_date', 'l.ledger_account_id', 'l.debit', 'l.credit']);
        foreach ($lines as $l) {
            $m = substr((string) $l->entry_date, 0, 7);
            $months[$m] ??= ['output' => 0, 'input' => 0];
            if ($l->ledger_account_id === $ids[Posting::TAX_PAYABLE]) {
                $months[$m]['output'] += Money::cents($l->credit) - Money::cents($l->debit);
            } else {
                $months[$m]['input'] += Money::cents($l->debit) - Money::cents($l->credit);
            }
        }
        ksort($months);
        $rows = [];
        $out = 0;
        $in = 0;
        foreach ($months as $m => $v) {
            $out += $v['output'];
            $in += $v['input'];
            $rows[] = [$m, Money::amount($v['output']), Money::amount($v['input']), Money::amount($v['output'] - $v['input'])];
        }

        return ['columns' => ['month', 'output', 'input', 'net'], 'rows' => $rows, 'totals' => ['output' => Money::amount($out), 'input' => Money::amount($in), 'net' => Money::amount($out - $in)]];
    }
}
