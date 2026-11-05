<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\FeeCarryover;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closing a year's accounts: what a student still owes for the old year moves to the new one.
 *
 * It is done the way an accountant does it, so every report stays right without special cases: the old fees are closed by a
 * "carried forward" entry (a payment that is not money: it is never counted as collected), and a new fee of kind "carried" with the
 * same amount, in installments, opens in the new year. The old year then shows nothing left to pay, the new year shows the balance,
 * and the student's statement runs on from one year to the next. Every move is recorded and can be undone while nothing was paid on it.
 */
class YearEndCarryOver
{
    public function __construct(private readonly FeeBilling $billing) {}

    /**
     * Students who still owe something for the year.
     *
     * @return Collection<int, array{student_id: int, student_number: string, name_ar: string, name_en: ?string, remaining: string, overdue: string}>
     */
    public function preview(AcademicYear $from): Collection
    {
        $today = now()->startOfDay();

        return StudentFee::with(['installments', 'student'])->where('academic_year_id', $from->id)->get()->groupBy('student_id')
            ->map(function (Collection $fees) use ($today): ?array {
                $installments = $fees->flatMap->installments;
                $remaining = (int) $installments->sum->remainingCents();
                if ($remaining <= 0) {
                    return null;
                }
                $overdue = (int) $installments->filter(fn ($i) => $i->due_date->lt($today))->sum->remainingCents();
                $student = $fees->first()->student;

                return ['student_id' => $student->id, 'student_number' => $student->student_number, 'name_ar' => $student->name_ar, 'name_en' => $student->name_en, 'remaining' => Money::amount($remaining), 'overdue' => Money::amount($overdue)];
            })->filter()->sortBy('student_number')->values();
    }

    /**
     * @param  list<int>|null  $studentIds  null = every student who owes something
     * @return array{carried: int, total: string}
     */
    public function run(AcademicYear $from, AcademicYear $to, string $firstDue, int $installments, ?array $studentIds, User $by): array
    {
        if ($from->id === $to->id || $to->starts_on->lte($from->starts_on)) {
            throw ValidationException::withMessages(['to_year_id' => [__('finance.carry_year_order')]]);
        }
        $count = 0;
        $sum = 0;
        $wanted = $studentIds === null ? null : array_flip($studentIds);
        foreach ($this->preview($from) as $row) {
            if ($wanted !== null && ! isset($wanted[$row['student_id']])) {
                continue;
            }
            $sum += $this->carry(Student::findOrFail($row['student_id']), $from, $to, $firstDue, $installments, $by);
            $count++;
        }

        return ['carried' => $count, 'total' => Money::amount($sum)];
    }

    /** Moves one student's whole balance; returns the cents moved. */
    private function carry(Student $student, AcademicYear $from, AcademicYear $to, string $firstDue, int $installments, User $by): int
    {
        return DB::transaction(function () use ($student, $from, $to, $firstDue, $installments, $by): int {
            $paymentIds = [];
            $total = 0;
            foreach (StudentFee::where('student_id', $student->id)->where('academic_year_id', $from->id)->orderBy('id')->get() as $fee) {
                $left = $this->billing->remainingCents($fee);
                if ($left <= 0) {
                    continue;
                }
                $payment = $this->billing->recordPayment($fee, Money::amount($left), Payment::CARRY, now()->toDateString(), $by, null, __('finance.carry_note', ['year' => $to->name]));
                $paymentIds[] = $payment->id;
                $total += $left;
            }
            if ($total <= 0) {
                return 0;
            }
            $schedule = $this->billing->evenSchedule($total, $installments, $firstDue);
            $fee = $this->billing->createFee($student, $to, __('finance.carry_description', ['year' => $from->name]), Money::amount($total), '0', $schedule, null, 'carried');
            FeeCarryover::create(['student_id' => $student->id, 'from_year_id' => $from->id, 'to_year_id' => $to->id, 'amount' => Money::amount($total), 'payment_ids' => $paymentIds, 'new_fee_id' => $fee->id, 'created_by' => $by->id]);

            return $total;
        });
    }

    /** Undoes one move, if nothing was paid on the new fee: the old fees owe again and the new fee goes. */
    public function revert(FeeCarryover $move, User $by): void
    {
        if ($move->reverted_at !== null) {
            throw ValidationException::withMessages(['move' => [__('finance.carry_already_reverted')]]);
        }
        $fee = $move->newFee;
        if ($fee !== null && $fee->payments()->exists()) {
            throw ValidationException::withMessages(['move' => [__('finance.carry_has_payments')]]);
        }
        DB::transaction(function () use ($move, $fee, $by): void {
            foreach (Payment::whereIn('id', $move->payment_ids)->get() as $payment) {
                $this->billing->voidPayment($payment);
            }
            $fee?->delete();
            $move->update(['reverted_at' => now(), 'reverted_by' => $by->id, 'new_fee_id' => null]);
        });
    }
}
