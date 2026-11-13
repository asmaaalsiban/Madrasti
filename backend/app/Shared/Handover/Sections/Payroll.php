<?php

declare(strict_types=1);

namespace App\Shared\Handover\Sections;

use App\Shared\Handover\HandoverSection;
use App\Shared\Handover\HandoverSheet as S;
use Illuminate\Support\Facades\DB;

/** The monthly payroll runs, the payslips and their items. */
class Payroll implements HandoverSection
{
    public function name(): string
    {
        return 'payroll';
    }

    public function write(string $dir): void
    {
        S::write($dir, 'payroll_runs', ['month', 'status', 'prepared_by', 'approved_by', 'approved_at', 'note'], S::stream(
            DB::table('payroll_runs as r')->leftJoin('users as p', 'p.id', '=', 'r.prepared_by')->leftJoin('users as a', 'a.id', '=', 'r.approved_by')->orderBy('r.month')
                ->select('r.month', 'r.status', 'p.name as prepared', 'a.name as approved', 'r.approved_at', 'r.return_note'),
            fn ($r) => [S::date($r->month), $r->status, $r->prepared, $r->approved, $r->approved_at, $r->return_note],
        ));

        S::write($dir, 'payslips', ['month', 'staff_member', 'base_salary', 'additions', 'deductions', 'net', 'paid_on', 'method', 'reference'], S::stream(
            DB::table('payslips as s')->join('payroll_runs as r', 'r.id', '=', 's.payroll_run_id')->join('users as u', 'u.id', '=', 's.user_id')->orderBy('r.month')->orderBy('s.id')
                ->select('r.month', 'u.name', 's.base_salary', 's.additions', 's.deductions', 's.net', 's.paid_on', 's.payment_method', 's.reference'),
            fn ($r) => [S::date($r->month), $r->name, S::money($r->base_salary), S::money($r->additions), S::money($r->deductions), S::money($r->net), S::date($r->paid_on), $r->payment_method, $r->reference],
        ));

        S::write($dir, 'payslip_items', ['month', 'staff_member', 'kind', 'label', 'amount'], S::stream(
            DB::table('payslip_items as i')->join('payslips as s', 's.id', '=', 'i.payslip_id')->join('payroll_runs as r', 'r.id', '=', 's.payroll_run_id')->join('users as u', 'u.id', '=', 's.user_id')
                ->orderBy('r.month')->orderBy('i.id')
                ->select('r.month', 'u.name', 'i.kind', 'i.label', 'i.amount'),
            fn ($r) => [S::date($r->month), $r->name, $r->kind, $r->label, S::money($r->amount)],
        ));
    }
}
