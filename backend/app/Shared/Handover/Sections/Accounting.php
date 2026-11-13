<?php

declare(strict_types=1);

namespace App\Shared\Handover\Sections;

use App\Modules\Finance\Services\AccountingReports;
use App\Shared\Handover\HandoverSection;
use App\Shared\Handover\HandoverSheet as S;
use Illuminate\Support\Facades\DB;

/** Fees, installments, payments with receipts, money accounts, expenses, the journal, the yearly reports and the budgets. */
class Accounting implements HandoverSection
{
    public function name(): string
    {
        return 'accounting';
    }

    private function receipt(?int $paymentId): ?string
    {
        return $paymentId === null ? null : 'R-'.str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
    }

    public function write(string $dir): void
    {
        S::write($dir, 'fee_structures', ['year', 'grade', 'name', 'amount'], S::stream(
            DB::table('fee_structures as f')->join('academic_years as y', 'y.id', '=', 'f.academic_year_id')->join('grade_levels as g', 'g.id', '=', 'f.grade_level_id')
                ->orderBy('f.id')->select('y.name as year', 'g.name_ar as g_ar', 'g.name_en as g_en', 'f.name', 'f.amount'),
            fn ($r) => [$r->year, S::both($r->g_ar, $r->g_en), $r->name, S::money($r->amount)],
        ));

        S::write($dir, 'student_fees', ['student_number', 'name_ar', 'name_en', 'year', 'kind', 'description', 'total', 'discount'], S::stream(
            DB::table('student_fees as f')->join('students as s', 's.id', '=', 'f.student_id')->join('academic_years as y', 'y.id', '=', 'f.academic_year_id')
                ->orderBy('f.id')->select('s.student_number', 's.name_ar', 's.name_en', 'y.name as year', 'f.kind', 'f.description', 'f.total_amount', 'f.discount'),
            fn ($r) => [$r->student_number, $r->name_ar, $r->name_en, $r->year, $r->kind, $r->description, S::money($r->total_amount), S::money($r->discount)],
        ));

        S::write($dir, 'installments', ['student_number', 'name_ar', 'name_en', 'year', 'fee', 'number', 'due_date', 'amount', 'paid_amount', 'status'], S::stream(
            DB::table('installments as i')->join('student_fees as f', 'f.id', '=', 'i.student_fee_id')->join('students as s', 's.id', '=', 'f.student_id')->join('academic_years as y', 'y.id', '=', 'f.academic_year_id')
                ->orderBy('f.id')->orderBy('i.number')
                ->select('s.student_number', 's.name_ar', 's.name_en', 'y.name as year', 'f.description', 'i.number', 'i.due_date', 'i.amount', 'i.paid_amount', 'i.status'),
            fn ($r) => [$r->student_number, $r->name_ar, $r->name_en, $r->year, $r->description, (int) $r->number, S::date($r->due_date), S::money($r->amount), S::money($r->paid_amount), $r->status],
        ));

        S::write($dir, 'payments', ['receipt', 'student_number', 'name_ar', 'name_en', 'fee', 'installment', 'amount', 'method', 'reference', 'payer', 'paid_on', 'money_account', 'note'], S::stream(
            DB::table('payments as p')->leftJoin('student_fees as f', 'f.id', '=', 'p.student_fee_id')->leftJoin('students as s', 's.id', '=', 'f.student_id')
                ->leftJoin('installments as i', 'i.id', '=', 'p.installment_id')->leftJoin('money_accounts as m', 'm.id', '=', 'p.money_account_id')
                ->orderBy('p.id')
                ->select('p.id', 's.student_number', 's.name_ar', 's.name_en', 'f.description', 'i.number', 'p.amount', 'p.method', 'p.reference', 'p.payer_name', 'p.paid_on', 'm.name as account', 'p.note'),
            fn ($r) => [$this->receipt((int) $r->id), $r->student_number, $r->name_ar, $r->name_en, $r->description, $r->number === null ? null : (int) $r->number, S::money($r->amount), $r->method, $r->reference, $r->payer_name, S::date($r->paid_on), $r->account, $r->note],
        ));

        S::write($dir, 'money_accounts', ['name', 'type', 'opening_balance', 'opened_on', 'bank_name', 'iban', 'active'], S::stream(
            DB::table('money_accounts')->orderBy('id'),
            fn ($r) => [$r->name, $r->type, S::money($r->opening_balance), S::date($r->opened_on), $r->bank_name, $r->iban, $r->is_active ? 1 : 0],
        ));

        $entries = fn () => DB::table('account_entries as e')->join('money_accounts as m', 'm.id', '=', 'e.money_account_id')->orderBy('e.id')
            ->select('e.*', 'm.name as account');
        S::write($dir, 'account_entries', ['money_account', 'date', 'kind', 'amount', 'description', 'receipt', 'transfer_ref', 'source'], S::stream(
            $entries(),
            fn ($r) => [$r->account, S::date($r->entry_date), $r->kind, S::money($r->amount), $r->description, $this->receipt($r->payment_id === null ? null : (int) $r->payment_id), $r->transfer_ref, $r->source_type],
        ));
        S::write($dir, 'transfers', ['transfer_ref', 'date', 'money_account', 'kind', 'amount', 'description'], S::stream(
            $entries()->whereIn('e.kind', ['transfer_in', 'transfer_out']),
            fn ($r) => [$r->transfer_ref, S::date($r->entry_date), $r->account, $r->kind, S::money($r->amount), $r->description],
        ));

        S::write($dir, 'suppliers', ['name', 'phone', 'email', 'note', 'active'], S::stream(
            DB::table('suppliers')->orderBy('id'),
            fn ($r) => [$r->name, $r->phone, $r->email, $r->notes, $r->is_active ? 1 : 0],
        ));
        S::write($dir, 'expenses', ['date', 'supplier', 'category', 'description', 'invoice', 'amount', 'tax', 'due_date'], S::stream(
            DB::table('expenses as e')->leftJoin('suppliers as s', 's.id', '=', 'e.supplier_id')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')->orderBy('e.id')
                ->select('e.expense_date', 's.name as supplier', 'c.name_ar', 'c.name_en', 'e.description', 'e.reference', 'e.amount', 'e.tax_amount', 'e.due_date'),
            fn ($r) => [S::date($r->expense_date), $r->supplier, S::both($r->name_ar, $r->name_en), $r->description, $r->reference, S::money($r->amount), S::money($r->tax_amount), S::date($r->due_date)],
        ));
        S::write($dir, 'expense_payments', ['expense', 'money_account', 'amount', 'paid_on', 'method', 'reference', 'note'], S::stream(
            DB::table('expense_payments as p')->join('expenses as e', 'e.id', '=', 'p.expense_id')->join('money_accounts as m', 'm.id', '=', 'p.money_account_id')->orderBy('p.id')
                ->select('e.description', 'm.name as account', 'p.amount', 'p.paid_on', 'p.method', 'p.reference', 'p.note'),
            fn ($r) => [$r->description, $r->account, S::money($r->amount), S::date($r->paid_on), $r->method, $r->reference, $r->note],
        ));

        S::write($dir, 'ledger_accounts', ['code', 'name_ar', 'name_en', 'type', 'active'], S::stream(
            DB::table('ledger_accounts')->orderBy('code'),
            fn ($r) => [$r->code, $r->name_ar, $r->name_en, $r->type, $r->is_active ? 1 : 0],
        ));
        S::write($dir, 'journal', ['entry', 'date', 'memo', 'account_code', 'account', 'debit', 'credit', 'note'], S::stream(
            DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
                ->orderBy('e.id')->orderBy('l.id')
                ->select('e.id as entry', 'e.entry_date', 'e.memo', 'a.code', 'a.name_ar', 'a.name_en', 'l.debit', 'l.credit', 'l.note'),
            fn ($r) => [(int) $r->entry, S::date($r->entry_date), $r->memo, $r->code, S::both($r->name_ar, $r->name_en), S::money($r->debit), S::money($r->credit), $r->note],
        ));

        $this->reports($dir);

        S::write($dir, 'budgets', ['year', 'category', 'amount'], S::stream(
            DB::table('budgets as b')->join('academic_years as y', 'y.id', '=', 'b.academic_year_id')->join('expense_categories as c', 'c.id', '=', 'b.expense_category_id')->orderBy('b.id')
                ->select('y.name as year', 'c.name_ar', 'c.name_en', 'b.amount'),
            fn ($r) => [$r->year, S::both($r->name_ar, $r->name_en), S::money($r->amount)],
        ));
    }

    /** The accounting reports of every academic year, from the same service the screens use. */
    private function reports(string $dir): void
    {
        $reports = app(AccountingReports::class);
        foreach (DB::table('academic_years')->orderBy('starts_on')->cursor() as $year) {
            $from = (string) S::date($year->starts_on);
            $to = (string) S::date($year->ends_on);
            $slug = trim((string) preg_replace('/[^\p{L}\p{N}_-]+/u', '-', (string) $year->name), '-') ?: (string) $year->id;
            foreach ([
                'trial_balance' => $reports->trialBalance($to),
                'income_statement' => $reports->incomeStatement($from, $to),
                'balance_sheet' => $reports->balanceSheet($to),
                'tax_summary' => $reports->taxSummary($from, $to),
            ] as $stem => $report) {
                $file = $stem.'_'.$slug;
                if (is_file("$dir/$file.xlsx")) {
                    $file .= '-'.$year->id; // two years with the same name
                }
                $columns = $report['columns'];
                $rows = $report['rows'];
                foreach ($report['totals'] as $key => $value) { // the totals as last rows: the label first, the amount last
                    $total = array_fill(0, count($columns), null);
                    $total[0] = S::label('cols', (string) $key);
                    $total[count($columns) - 1] = $value;
                    $rows[] = $total;
                }
                S::write($dir, $stem, $columns, $rows, (string) $year->name, $file);
            }
        }
    }
}
