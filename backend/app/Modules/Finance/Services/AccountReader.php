<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Reads of the money side: one student's statement and the school-wide balances. */
class AccountReader
{
    /**
     * One student's statement: every fee with its installments and payments, and the totals.
     *
     * @return array<string, mixed>
     */
    public function statement(Student $student, ?AcademicYear $year = null): array
    {
        $fees = StudentFee::with(['installments', 'payments.receiver', 'payments.allocations'])->where('student_id', $student->id)
            ->when($year, fn ($q) => $q->where('academic_year_id', $year->id))->orderBy('id')->get();

        $total = $paid = $overdue = 0;
        $out = [];
        foreach ($fees as $fee) {
            $net = Money::cents($fee->total_amount) - Money::cents($fee->discount);
            $feePaid = (int) $fee->installments->sum(fn (Installment $i) => Money::cents($i->paid_amount));
            $feeOverdue = (int) $fee->installments->sum(fn (Installment $i) => $i->effectiveStatus() === 'overdue' ? $i->remainingCents() : 0);
            $total += $net;
            $paid += $feePaid;
            $overdue += $feeOverdue;
            $out[] = [
                'id' => $fee->id, 'description' => $fee->description, 'total' => $fee->total_amount, 'discount' => $fee->discount, 'net' => Money::amount($net),
                'paid' => Money::amount($feePaid), 'remaining' => Money::amount($net - $feePaid), 'overdue' => Money::amount($feeOverdue),
                'installments' => $fee->installments->map(fn (Installment $i) => [
                    'id' => $i->id, 'number' => $i->number, 'due_date' => $i->due_date->format('Y-m-d'), 'amount' => $i->amount, 'paid' => $i->paid_amount,
                    'remaining' => Money::amount($i->remainingCents()), 'status' => $i->effectiveStatus(),
                ])->values(),
                'payments' => $fee->payments->map(fn (Payment $p) => $this->payment($p))->values(),
            ];
        }

        return [
            'student' => ['id' => $student->id, 'name_ar' => $student->name_ar, 'name_en' => $student->name_en, 'student_number' => $student->student_number],
            'totals' => ['net' => Money::amount($total), 'paid' => Money::amount($paid), 'remaining' => Money::amount($total - $paid), 'overdue' => Money::amount($overdue)],
            'fees' => $out,
        ];
    }

    /** @return array<string, mixed> */
    public function payment(Payment $p): array
    {
        return [
            'id' => $p->id, 'receipt' => $p->receiptNumber(), 'amount' => $p->amount, 'method' => $p->method, 'reference' => $p->reference, 'note' => $p->note,
            'paid_on' => $p->paid_on->format('Y-m-d'), 'received_by' => $p->receiver?->name, 'installments' => $p->allocations->map(fn ($a) => ['installment_id' => $a->installment_id, 'amount' => $a->amount])->values(),
        ];
    }

    /**
     * Balances of every student enrolled this year, filtered and paged: what they owe (net), paid, remaining and overdue.
     *
     * @param  array{grade_level_id?: int, classroom_id?: int, status?: string, search?: string}  $filters
     */
    public function accounts(AcademicYear $year, array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $today = now()->toDateString();
        $fees = DB::table('student_fees')->where('academic_year_id', $year->id)->groupBy('student_id')->selectRaw('student_id, sum(total_amount - discount) as net');
        $paid = DB::table('installments')->join('student_fees', 'student_fees.id', '=', 'installments.student_fee_id')->where('student_fees.academic_year_id', $year->id)->groupBy('student_fees.student_id')
            ->selectRaw('student_fees.student_id as student_id, sum(installments.paid_amount) as paid, sum(case when installments.due_date < ? and installments.paid_amount < installments.amount then installments.amount - installments.paid_amount else 0 end) as overdue', [$today]);

        $query = DB::table('students')->join('enrollments', fn ($j) => $j->on('enrollments.student_id', '=', 'students.id')->where('enrollments.academic_year_id', $year->id))
            ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')->whereNull('students.deleted_at')
            ->leftJoinSub($fees, 'f', 'f.student_id', '=', 'students.id')->leftJoinSub($paid, 'p', 'p.student_id', '=', 'students.id')
            ->selectRaw('students.id, students.name_ar, students.name_en, students.student_number, classrooms.name as classroom, coalesce(f.net, 0) as net, coalesce(p.paid, 0) as paid, coalesce(p.overdue, 0) as overdue')
            ->when($filters['grade_level_id'] ?? null, fn ($q, $v) => $q->where('classrooms.grade_level_id', $v))
            ->when($filters['classroom_id'] ?? null, fn ($q, $v) => $q->where('classrooms.id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('students.name_ar', 'like', "%$v%")->orWhere('students.name_en', 'like', "%$v%")->orWhere('students.student_number', 'like', "%$v%")))
            ->when(($filters['status'] ?? null) === 'overdue', fn ($q) => $q->whereRaw('coalesce(p.overdue, 0) > 0'))
            ->when(($filters['status'] ?? null) === 'owing', fn ($q) => $q->whereRaw('coalesce(f.net, 0) - coalesce(p.paid, 0) > 0'))
            ->when(($filters['status'] ?? null) === 'settled', fn ($q) => $q->whereRaw('coalesce(f.net, 0) > 0 and coalesce(f.net, 0) - coalesce(p.paid, 0) <= 0'))
            ->orderBy('classrooms.name')->orderBy('students.name_ar');

        return $query->paginate($perPage)->through(fn ($r) => [
            'id' => $r->id, 'name_ar' => $r->name_ar, 'name_en' => $r->name_en, 'student_number' => $r->student_number, 'classroom' => $r->classroom,
            'net' => Money::amount(Money::cents($r->net)), 'paid' => Money::amount(Money::cents($r->paid)), 'remaining' => Money::amount(Money::cents($r->net) - Money::cents($r->paid)), 'overdue' => Money::amount(Money::cents($r->overdue)),
        ]);
    }

    /**
     * Fees, paid, remaining and overdue of some students this year (the Students page shows them): student id => amounts.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array{net: string, paid: string, remaining: string, overdue: string}>
     */
    public function balances(array $studentIds, AcademicYear $year): array
    {
        $today = now()->toDateString();
        $net = DB::table('student_fees')->where('academic_year_id', $year->id)->whereIn('student_id', $studentIds)->groupBy('student_id')->selectRaw('student_id, sum(total_amount - discount) as net')->pluck('net', 'student_id');
        $paid = DB::table('installments')->join('student_fees', 'student_fees.id', '=', 'installments.student_fee_id')->where('student_fees.academic_year_id', $year->id)->whereIn('student_fees.student_id', $studentIds)
            ->groupBy('student_fees.student_id')->selectRaw('student_fees.student_id as student_id, sum(installments.paid_amount) as paid, sum(case when installments.due_date < ? and installments.paid_amount < installments.amount then installments.amount - installments.paid_amount else 0 end) as overdue', [$today])->get()->keyBy('student_id');

        $out = [];
        foreach ($studentIds as $id) {
            $n = Money::cents($net[$id] ?? 0);
            $p = Money::cents($paid[$id]->paid ?? 0);
            $out[$id] = ['net' => Money::amount($n), 'paid' => Money::amount($p), 'remaining' => Money::amount($n - $p), 'overdue' => Money::amount(Money::cents($paid[$id]->overdue ?? 0))];
        }

        return $out;
    }

    /**
     * School totals for the year: expected, collected, remaining, overdue, collected this month, and the same per grade.
     *
     * @return array<string, mixed>
     */
    public function summary(AcademicYear $year): array
    {
        $today = now()->toDateString();
        $net = (float) DB::table('student_fees')->where('academic_year_id', $year->id)->sum(DB::raw('total_amount - discount'));
        $rows = DB::table('installments')->join('student_fees', 'student_fees.id', '=', 'installments.student_fee_id')->where('student_fees.academic_year_id', $year->id)
            ->selectRaw('sum(installments.paid_amount) as paid, sum(case when installments.due_date < ? and installments.paid_amount < installments.amount then installments.amount - installments.paid_amount else 0 end) as overdue', [$today])->first();
        $month = (float) DB::table('payments')->join('student_fees', 'student_fees.id', '=', 'payments.student_fee_id')->where('student_fees.academic_year_id', $year->id)
            ->whereBetween('payments.paid_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('payments.amount');

        $paid = Money::cents($rows->paid);
        $total = Money::cents($net);

        return [
            'expected' => Money::amount($total), 'collected' => Money::amount($paid), 'remaining' => Money::amount($total - $paid), 'overdue' => Money::amount(Money::cents($rows->overdue)),
            'collected_this_month' => Money::amount(Money::cents($month)),
            'students_overdue' => (int) DB::table('installments')->join('student_fees', 'student_fees.id', '=', 'installments.student_fee_id')->where('student_fees.academic_year_id', $year->id)
                ->where('installments.due_date', '<', $today)->whereColumn('installments.paid_amount', '<', 'installments.amount')->distinct()->count('student_fees.student_id'),
        ];
    }
}
