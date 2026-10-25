<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
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
    public function createFee(Student $student, AcademicYear $year, string $description, string|float $total, string|float $discount, array $schedule, ?int $structureId = null, ?string $kind = null): StudentFee
    {
        $net = Money::cents($total) - Money::cents($discount);
        if ($net <= 0 || Money::cents($discount) < 0) {
            throw ValidationException::withMessages(['discount' => [__('finance.bad_amounts')]]);
        }
        if (array_sum(array_map(fn ($r) => Money::cents($r['amount']), $schedule)) !== $net || $schedule === []) {
            throw ValidationException::withMessages(['installments' => [__('finance.schedule_mismatch')]]);
        }

        return DB::transaction(function () use ($student, $year, $description, $total, $discount, $schedule, $structureId, $kind): StudentFee {
            $fee = StudentFee::create([
                'student_id' => $student->id, 'academic_year_id' => $year->id, 'fee_structure_id' => $structureId, 'kind' => $kind ?? ($structureId ? 'yearly' : 'other'),
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

    /**
     * A student placed in a section gets, automatically, the yearly fee of that grade (split by the fee's own plan) and, if given,
     * a transport fee (it differs from student to student: where they live). The discount is taken off the first fee of the grade.
     * Fees the student already has are skipped, so it can run again when the student moves or is saved.
     *
     * @return list<StudentFee> the fees that were created
     */
    public function onboard(Student $student, Classroom $classroom, float $discount = 0, float $transport = 0): array
    {
        $year = $classroom->academicYear ?? AcademicYear::findOrFail($classroom->academic_year_id);
        $structures = FeeStructure::where('academic_year_id', $year->id)->where('grade_level_id', $classroom->grade_level_id)
            ->where('auto_apply', true)->whereNotNull('first_due')->orderBy('id')->get();
        $given = StudentFee::where('student_id', $student->id)->where('academic_year_id', $year->id)->get();

        if ($discount > 0 && ($structures->isEmpty() || Money::cents($discount) >= Money::cents($structures->first()->amount))) {
            throw ValidationException::withMessages(['fee_discount' => [__('finance.discount_too_big')]]);
        }

        $created = [];
        foreach ($structures as $i => $structure) {
            if ($given->contains('fee_structure_id', $structure->id)) {
                continue;
            }
            $off = $i === 0 ? $discount : 0;
            $net = Money::cents($structure->amount) - Money::cents($off);
            $created[] = $this->createFee($student, $year, $structure->name, $structure->amount, $off, $this->evenSchedule($net, $structure->installments_count, $structure->first_due->toDateString(), $structure->every_months), $structure->id);
        }

        if ($transport > 0 && ($fee = $this->setTransport($student, $classroom, $transport)) !== null && $fee->wasRecentlyCreated) {
            $created[] = $fee;
        }

        return $created;
    }

    /**
     * Sets the student's transport fee to an amount (0 = no transport): the area a student lives in can change between the
     * terms. A new fee is split by the grade's plan; an existing one keeps what was paid and the rest is re-split over the
     * installments that are still open (a new one is added if all were closed, and open ones are dropped if nothing is left).
     * The total can never go below what was already paid.
     */
    public function setTransport(Student $student, Classroom $classroom, float $amount): ?StudentFee
    {
        $year = $classroom->academicYear ?? AcademicYear::findOrFail($classroom->academic_year_id);
        $fee = StudentFee::with('installments')->where('student_id', $student->id)->where('academic_year_id', $year->id)->where('kind', 'transport')->first();
        $cents = Money::cents($amount);
        if ($cents < 0) {
            throw ValidationException::withMessages(['transport_fee' => [__('finance.bad_amounts')]]);
        }

        if ($fee === null) {
            if ($cents === 0) {
                return null;
            }
            $plan = FeeStructure::where('academic_year_id', $year->id)->where('grade_level_id', $classroom->grade_level_id)->where('auto_apply', true)->whereNotNull('first_due')->orderBy('id')->first();
            $schedule = $plan
                ? $this->evenSchedule($cents, $plan->installments_count, $plan->first_due->toDateString(), $plan->every_months)
                : $this->evenSchedule($cents, 1, now()->addMonthNoOverflow()->startOfMonth()->toDateString());

            return $this->createFee($student, $year, __('finance.transport'), $amount, 0, $schedule, null, 'transport');
        }

        if ($cents === Money::cents($fee->total_amount)) {
            return $fee;
        }

        return DB::transaction(function () use ($fee, $cents): ?StudentFee {
            $paid = $this->paidCents($fee);
            if ($cents < $paid) {
                throw ValidationException::withMessages(['transport_fee' => [__('finance.below_paid', ['paid' => Money::amount($paid)])]]);
            }
            if ($cents === 0) {
                $fee->delete();

                return null;
            }

            $rest = $cents - $paid;
            $open = $fee->installments->filter(fn (Installment $i) => Money::cents($i->paid_amount) < Money::cents($i->amount))->values();
            if ($open->isEmpty() && $rest > 0) {
                $last = $fee->installments->last();
                $new = Installment::create(['student_fee_id' => $fee->id, 'number' => ($last?->number ?? 0) + 1, 'due_date' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(), 'amount' => '0.00', 'paid_amount' => 0, 'status' => 'pending']);
                $open = collect([$new]);
            }
            $each = $open->isEmpty() ? 0 : intdiv($rest, $open->count());
            foreach ($open as $i => $installment) {
                $share = $i === $open->count() - 1 ? $rest - $each * ($open->count() - 1) : $each;
                $amount = Money::cents($installment->paid_amount) + $share;
                if ($amount === 0) {
                    $installment->delete();

                    continue;
                }
                $installment->amount = Money::amount($amount);
                $installment->status = $installment->effectiveStatus();
                $installment->save();
            }
            $fee->update(['total_amount' => Money::amount($cents)]);

            return $fee->load('installments');
        });
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
