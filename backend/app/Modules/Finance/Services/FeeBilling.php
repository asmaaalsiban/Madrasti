<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Models\StudentFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Fees, installments and payments of students. Every change of money happens inside a transaction. */
class FeeBilling
{
    /**
     * Splits an amount into equal installments (the last one takes the remainder), the first due on `$firstDue`, then every `$everyMonths`.
     *
     * @return list<array{due_date: string, amount: string}>
     */
    public function evenSchedule(int $netCents, int $count, string $firstDue, int $everyMonths = 1): array
    {
        $each = intdiv($netCents, $count);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $cents = $i === $count - 1 ? $netCents - $each * ($count - 1) : $each;
            $rows[] = ['due_date' => Carbon::parse($firstDue)->addMonthsNoOverflow($i * $everyMonths)->toDateString(), 'amount' => Money::amount($cents)];
        }

        return $rows;
    }

    /**
     * A fee for one student with its installments; the installments must add up to total - discount.
     *
     * @param  list<array{due_date: string, amount: string|float|int}>  $schedule
     */
    public function createFee(Student $student, AcademicYear $year, string $description, string|float $total, string|float $discount, array $schedule, ?int $structureId = null): StudentFee
    {
        $net = Money::cents($total) - Money::cents($discount);
        if ($net <= 0 || Money::cents($discount) < 0) {
            throw ValidationException::withMessages(['discount' => [__('finance.bad_amounts')]]);
        }
        if (array_sum(array_map(fn ($r) => Money::cents($r['amount']), $schedule)) !== $net || $schedule === []) {
            throw ValidationException::withMessages(['installments' => [__('finance.schedule_mismatch')]]);
        }

        return DB::transaction(function () use ($student, $year, $description, $total, $discount, $schedule, $structureId): StudentFee {
            $fee = StudentFee::create([
                'student_id' => $student->id, 'academic_year_id' => $year->id, 'fee_structure_id' => $structureId,
                'description' => $description, 'total_amount' => $total, 'discount' => $discount,
            ]);
            foreach (array_values($schedule) as $i => $row) {
                Installment::create(['student_fee_id' => $fee->id, 'number' => $i + 1, 'due_date' => $row['due_date'], 'amount' => $row['amount'], 'paid_amount' => 0, 'status' => 'pending']);
            }

            return $fee->load('installments');
        });
    }

    /** Gives the structure's fee to every student of its grade enrolled this year who does not have it yet. Returns how many got it. */
    public function applyStructure(FeeStructure $structure, int $count, string $firstDue, int $everyMonths, float $discount = 0): int
    {
        $year = $structure->academicYear;
        $students = Student::whereIn('id', Enrollment::where('academic_year_id', $year->id)
            ->whereIn('classroom_id', fn ($q) => $q->select('id')->from('classrooms')->where('grade_level_id', $structure->grade_level_id))->select('student_id'))
            ->whereNotIn('id', StudentFee::where('fee_structure_id', $structure->id)->select('student_id'))->get();

        foreach ($students as $student) {
            $net = Money::cents($structure->amount) - Money::cents($discount);
            $this->createFee($student, $year, $structure->name, $structure->amount, $discount, $this->evenSchedule($net, $count, $firstDue, $everyMonths), $structure->id);
        }

        return $students->count();
    }

    /** What is still owed on a fee, in cents. */
    public function remainingCents(StudentFee $fee): int
    {
        return Money::cents($fee->total_amount) - Money::cents($fee->discount) - $this->paidCents($fee);
    }

    public function paidCents(StudentFee $fee): int
    {
        return (int) $fee->installments()->get()->sum(fn (Installment $i) => Money::cents($i->paid_amount));
    }

    /**
     * Records a payment and spreads it over the installments, the oldest unpaid first (or the chosen one first).
     * It can never be more than what is still owed.
     */
    public function recordPayment(StudentFee $fee, float|string $amount, string $method, string $paidOn, User $by, ?string $reference = null, ?string $note = null, ?int $installmentId = null): Payment
    {
        return DB::transaction(function () use ($fee, $amount, $method, $paidOn, $by, $reference, $note, $installmentId): Payment {
            $fee = StudentFee::whereKey($fee->id)->lockForUpdate()->firstOrFail();
            $cents = Money::cents($amount);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => [__('finance.bad_amounts')]]);
            }
            if ($cents > $this->remainingCents($fee)) {
                throw ValidationException::withMessages(['amount' => [__('finance.more_than_owed', ['owed' => Money::amount($this->remainingCents($fee))])]]);
            }

            $installments = $fee->installments()->get();
            if ($installmentId !== null) {
                abort_unless($installments->contains('id', $installmentId), 422);
                $installments = $installments->sortBy(fn ($i) => $i->id === $installmentId ? 0 : 1)->values();
            }

            $payment = Payment::create([
                'student_fee_id' => $fee->id, 'installment_id' => $installmentId, 'amount' => $amount, 'method' => $method, 'reference' => $reference,
                'paid_on' => $paidOn, 'received_by' => $by->id, 'note' => $note,
            ]);

            $left = $cents;
            foreach ($installments as $installment) {
                $take = min($left, $installment->remainingCents());
                if ($take <= 0) {
                    continue;
                }
                PaymentAllocation::create(['payment_id' => $payment->id, 'installment_id' => $installment->id, 'amount' => Money::amount($take)]);
                $installment->paid_amount = Money::amount(Money::cents($installment->paid_amount) + $take);
                $installment->status = $installment->effectiveStatus();
                $installment->save();
                $left -= $take;
            }

            return $payment->load('allocations');
        });
    }

    /** Cancels a payment (a mistake): the installments get back what it covered. */
    public function voidPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            foreach ($payment->allocations()->get() as $allocation) {
                $installment = Installment::whereKey($allocation->installment_id)->lockForUpdate()->first();
                if ($installment !== null) {
                    $installment->paid_amount = Money::amount(max(0, Money::cents($installment->paid_amount) - Money::cents($allocation->amount)));
                    $installment->status = $installment->effectiveStatus();
                    $installment->save();
                }
            }
            $payment->delete();
        });
    }

    /** Changes the discount of a fee that has no payment yet and spreads the new net amount evenly over the same installments. */
    public function changeDiscount(StudentFee $fee, float|string $discount): StudentFee
    {
        return DB::transaction(function () use ($fee, $discount): StudentFee {
            if ($this->paidCents($fee) > 0 || $fee->payments()->exists()) {
                throw ValidationException::withMessages(['discount' => [__('finance.has_payments')]]);
            }
            $net = Money::cents($fee->total_amount) - Money::cents($discount);
            if ($net <= 0 || Money::cents($discount) < 0) {
                throw ValidationException::withMessages(['discount' => [__('finance.bad_amounts')]]);
            }
            $installments = $fee->installments()->get();
            $schedule = $this->evenSchedule($net, $installments->count(), $installments->first()->due_date->toDateString(), 1);
            foreach ($installments as $i => $installment) {
                $installment->update(['amount' => $schedule[$i]['amount']]);
            }
            $fee->update(['discount' => $discount]);

            return $fee->load('installments');
        });
    }

    /** Keeps the stored status of every unpaid installment in step with today's date (the nightly job calls it). */
    public function refreshOverdue(): int
    {
        $changed = 0;
        foreach (Installment::whereColumn('paid_amount', '<', 'amount')->get() as $installment) {
            $status = $installment->effectiveStatus();
            if ($installment->status !== $status) {
                $installment->update(['status' => $status]);
                $changed++;
            }
        }

        return $changed;
    }
}
