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
use App\Modules\Finance\Models\StudentFeeItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fees, installments and payments of students. A student has ONE schedule per yearly fee: everything added later (transport,
 * books, a discount) is spread over the installments still open, never put in a schedule of its own. Every change of money
 * happens inside a transaction.
 */
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
     * A fee for one student with its installments; the installments must add up to total - discount. `$items` are the lines that
     * make up the total (default: one line for the fee and one negative line for a discount).
     *
     * @param  list<array{due_date: string, amount: string|float|int}>  $schedule
     * @param  list<array{kind: string, description: string, amount: float|string}>|null  $items
     */
    public function createFee(Student $student, AcademicYear $year, string $description, string|float $total, string|float $discount, array $schedule, ?int $structureId = null, ?string $kind = null, ?array $items = null): StudentFee
    {
        $net = Money::cents($total) - Money::cents($discount);
        if ($net <= 0 || Money::cents($discount) < 0) {
            throw ValidationException::withMessages(['discount' => [__('finance.bad_amounts')]]);
        }
        if (array_sum(array_map(fn ($r) => Money::cents($r['amount']), $schedule)) !== $net || $schedule === []) {
            throw ValidationException::withMessages(['installments' => [__('finance.schedule_mismatch')]]);
        }
        $kind ??= $structureId ? 'yearly' : 'other';
        $items ??= array_values(array_filter([
            ['kind' => $kind, 'description' => $description, 'amount' => $total],
            Money::cents($discount) > 0 ? ['kind' => 'discount', 'description' => __('finance.discount_line'), 'amount' => -1 * (float) $discount] : null,
        ]));

        return DB::transaction(function () use ($student, $year, $description, $total, $discount, $schedule, $structureId, $kind, $items): StudentFee {
            $fee = StudentFee::create([
                'student_id' => $student->id, 'academic_year_id' => $year->id, 'fee_structure_id' => $structureId, 'kind' => $kind,
                'description' => $description, 'total_amount' => $total, 'discount' => $discount,
            ]);
            foreach (array_values($schedule) as $i => $row) {
                Installment::create(['student_fee_id' => $fee->id, 'number' => $i + 1, 'due_date' => $row['due_date'], 'amount' => $row['amount'], 'paid_amount' => 0, 'status' => 'pending']);
            }
            foreach ($items as $item) {
                StudentFeeItem::create(['student_fee_id' => $fee->id] + $item);
            }

            return $fee->load(['installments', 'items']);
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
     * A student placed in a section gets, automatically, the yearly fee of that grade (split by the fee's own plan). A transport fee
     * (it differs per student: where they live) goes INTO that same schedule, and so does the discount (taken off the first fee).
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
        $transportDone = false;
        foreach ($structures as $i => $structure) {
            if ($given->contains('fee_structure_id', $structure->id)) {
                continue;
            }
            $off = $i === 0 ? $discount : 0;
            $extra = $i === 0 && $transport > 0 ? $transport : 0; // transport rides in the first fee's schedule
            $transportDone = $transportDone || $extra > 0;
            $total = Money::cents($structure->amount) + Money::cents($extra);
            $items = array_values(array_filter([
                ['kind' => 'yearly', 'description' => $structure->name, 'amount' => $structure->amount],
                $extra > 0 ? ['kind' => 'transport', 'description' => __('finance.transport'), 'amount' => $extra] : null,
                $off > 0 ? ['kind' => 'discount', 'description' => __('finance.discount_line'), 'amount' => -1 * $off] : null,
            ]));
            $schedule = $this->evenSchedule($total - Money::cents($off), $structure->installments_count, $structure->first_due->toDateString(), $structure->every_months);
            $created[] = $this->createFee($student, $year, $structure->name, Money::amount($total), $off, $schedule, $structure->id, 'yearly', $items);
        }

        if ($transport > 0 && ! $transportDone && ($fee = $this->setTransport($student, $classroom, $transport)) !== null && $fee->wasRecentlyCreated) {
            $created[] = $fee;
        }

        return $created;
    }

    /** The fee that holds a student's schedule this year: the first yearly fee, else the first one that is not a lone transport fee. */
    public function mainFee(Student $student, AcademicYear $year): ?StudentFee
    {
        $fees = StudentFee::with(['installments', 'items'])->where('student_id', $student->id)->where('academic_year_id', $year->id)->orderBy('id')->get();

        return $fees->firstWhere('kind', 'yearly') ?? $fees->first(fn (StudentFee $f) => $f->kind !== 'transport');
    }

    /** True if something can still be added to the student's schedule (a main fee exists). */
    public function hasSchedule(Student $student, AcademicYear $year): bool
    {
        return $this->mainFee($student, $year) !== null;
    }

    /**
     * Adds a charge (transport, books...) to an existing fee: the amount is spread evenly over the installments that are still
     * open, so a student who owes 700 over 7 months with 100 more added owes 800 over the same 7 months.
     */
    public function addCharge(StudentFee $fee, string $kind, string $description, float|string $amount): StudentFee
    {
        $cents = Money::cents($amount);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => [__('finance.bad_amounts')]]);
        }

        return DB::transaction(function () use ($fee, $kind, $description, $cents): StudentFee {
            $fee = StudentFee::whereKey($fee->id)->lockForUpdate()->firstOrFail();
            $this->adjustOpen($fee, $cents, 'amount');
            StudentFeeItem::create(['student_fee_id' => $fee->id, 'kind' => $kind, 'description' => $description, 'amount' => Money::amount($cents)]);
            $fee->update(['total_amount' => Money::amount(Money::cents($fee->total_amount) + $cents)]);

            return $fee->load(['installments', 'items']);
        });
    }

    /**
     * Raises (positive) or lowers (negative) what the open installments owe, evenly. An installment never goes below what was
     * already paid on it; if nothing is open and something is added, a new installment next month takes it; if more is taken off than
     * the open installments still owe, it is refused (that needs a refund, which the accountant handles).
     */
    private function adjustOpen(StudentFee $fee, int $deltaCents, string $field): void
    {
        $open = $fee->installments()->get()->filter(fn (Installment $i) => $i->remainingCents() > 0)->values();

        if ($deltaCents > 0) {
            if ($open->isEmpty()) {
                $last = $fee->installments()->get()->last();
                $open = collect([Installment::create(['student_fee_id' => $fee->id, 'number' => ($last?->number ?? 0) + 1, 'due_date' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(), 'amount' => '0.00', 'paid_amount' => 0, 'status' => 'pending'])]);
            }
            $each = intdiv($deltaCents, $open->count());
            foreach ($open as $i => $installment) {
                $share = $i === $open->count() - 1 ? $deltaCents - $each * ($open->count() - 1) : $each;
                $this->setAmount($installment, Money::cents($installment->amount) + $share);
            }

            return;
        }

        $take = -$deltaCents;
        $room = (int) $open->sum(fn (Installment $i) => $i->remainingCents());
        if ($take > $room) {
            throw ValidationException::withMessages([$field => [__('finance.below_paid', ['paid' => Money::amount($this->paidCents($fee))])]]);
        }
        // Take evenly, but never more than an installment still owes: the ones that run out hand the rest to the others.
        $left = $take;
        $pool = $open->all();
        while ($left > 0 && $pool !== []) {
            $each = max(1, intdiv($left, count($pool)));
            foreach ($pool as $k => $installment) {
                $cut = min($each, $installment->remainingCents(), $left);
                $this->setAmount($installment, Money::cents($installment->amount) - $cut);
                $left -= $cut;
                if ($installment->remainingCents() <= 0) {
                    unset($pool[$k]);
                }
                if ($left <= 0) {
                    break;
                }
            }
            $pool = array_values($pool);
        }
    }

    private function setAmount(Installment $installment, int $cents): void
    {
        if ($cents <= 0 && Money::cents($installment->paid_amount) === 0) {
            $installment->delete();

            return;
        }
        $installment->amount = Money::amount($cents);
        $installment->status = $installment->effectiveStatus();
        $installment->save();
    }

    /**
     * Sets the student's transport fee to an amount (0 = no transport): the area a student lives in can change between the
     * terms. It is a line of the student's schedule: more is spread over the open installments, less is taken off them (never
     * below what was paid). A student with no schedule yet gets a transport fee of its own.
     */
    public function setTransport(Student $student, Classroom $classroom, float $amount): ?StudentFee
    {
        $year = $classroom->academicYear ?? AcademicYear::findOrFail($classroom->academic_year_id);
        $cents = Money::cents($amount);
        if ($cents < 0) {
            throw ValidationException::withMessages(['transport_fee' => [__('finance.bad_amounts')]]);
        }

        $main = $this->mainFee($student, $year);
        if ($main !== null) {
            return $this->setTransportLine($main, $cents);
        }

        $fee = StudentFee::with('installments')->where('student_id', $student->id)->where('academic_year_id', $year->id)->where('kind', 'transport')->first();
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

        return $this->setTransportLine($fee, $cents);
    }

    /** Changes the transport line of a fee to `$cents` (the fee that holds the schedule, or the lone transport fee). */
    private function setTransportLine(StudentFee $fee, int $cents): ?StudentFee
    {
        $line = $fee->items->firstWhere('kind', 'transport');
        $old = $line ? Money::cents($line->amount) : ($fee->kind === 'transport' && $fee->items->isEmpty() ? Money::cents($fee->total_amount) : 0);
        $delta = $cents - $old;
        if ($delta === 0) {
            return $fee;
        }

        return DB::transaction(function () use ($fee, $cents, $delta, $line): ?StudentFee {
            $fee = StudentFee::whereKey($fee->id)->lockForUpdate()->firstOrFail();
            $loneTransport = $fee->kind === 'transport';
            if ($loneTransport && $cents === 0 && $this->paidCents($fee) === 0) {
                $fee->delete();

                return null;
            }
            $this->adjustOpen($fee, $delta, 'transport_fee');
            if ($cents === 0) {
                $line?->delete();
            } elseif ($line) {
                $line->update(['amount' => Money::amount($cents)]);
            } else {
                StudentFeeItem::create(['student_fee_id' => $fee->id, 'kind' => 'transport', 'description' => __('finance.transport'), 'amount' => Money::amount($cents)]);
            }
            $fee->update(['total_amount' => Money::amount(Money::cents($fee->total_amount) + $delta)]);

            return $fee->load(['installments', 'items']);
        });
    }

    /**
     * The first payment taken at registration: it goes to the first fee up to what it owes and the rest to the next one. One
     * receipt for each fee it touches. It can never be more than the fees owe.
     *
     * @param  list<StudentFee>  $fees
     * @return list<Payment>
     */
    public function payFirst(array $fees, float $amount, string $method, User $by, ?string $payerName = null): array
    {
        $left = Money::cents($amount);
        $owed = array_sum(array_map(fn (StudentFee $f) => $this->remainingCents($f), $fees));
        if ($left <= 0 || $left > $owed) {
            throw ValidationException::withMessages(['first_payment' => [__('finance.first_payment_too_big', ['owed' => Money::amount($owed)])]]);
        }

        $payments = [];
        foreach ($fees as $fee) {
            $take = min($left, $this->remainingCents($fee));
            if ($take > 0) {
                $payments[] = $this->recordPayment($fee, Money::amount($take), $method, now()->toDateString(), $by, null, __('finance.first_payment_note'), null, $payerName);
                $left -= $take;
            }
        }

        return $payments;
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
     * It can never be more than what is still owed. `$payerName` is who paid: the receipt is written in their name.
     */
    public function recordPayment(StudentFee $fee, float|string $amount, string $method, string $paidOn, User $by, ?string $reference = null, ?string $note = null, ?int $installmentId = null, ?string $payerName = null, ?int $accountId = null): Payment
    {
        // The account is checked first: a payment never half-succeeds because the chosen account cannot be used.
        $account = $method === Payment::CARRY ? null : app(MoneyBook::class)->accountFor($method, $accountId);

        return DB::transaction(function () use ($fee, $amount, $method, $paidOn, $by, $reference, $note, $installmentId, $payerName, $account): Payment {
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
                'student_fee_id' => $fee->id, 'installment_id' => $installmentId, 'amount' => $amount, 'method' => $method, 'reference' => $reference, 'payer_name' => $payerName,
                'paid_on' => $paidOn, 'received_by' => $by->id, 'note' => $note,
            ]);
            if ($account !== null) {
                app(MoneyBook::class)->receive($payment, $account); // the money goes into the cash box or the bank account
            }

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
            app(MoneyBook::class)->release($payment); // leaves the account's book too (not once it was matched with the bank's statement)
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

    /**
     * Changes the discount of a fee at any time: a bigger discount takes the difference off the open installments (never below
     * what was paid), a smaller one adds it back to them. The discount is shown as a negative line.
     */
    public function changeDiscount(StudentFee $fee, float|string $discount): StudentFee
    {
        return DB::transaction(function () use ($fee, $discount): StudentFee {
            $fee = StudentFee::with('items')->whereKey($fee->id)->lockForUpdate()->firstOrFail();
            $new = Money::cents($discount);
            $old = Money::cents($fee->discount);
            if ($new < 0 || $new >= Money::cents($fee->total_amount)) {
                throw ValidationException::withMessages(['discount' => [__('finance.bad_amounts')]]);
            }
            if ($new !== $old) {
                $this->adjustOpen($fee, $old - $new, 'discount');
                $line = $fee->items->firstWhere('kind', 'discount');
                if ($new === 0) {
                    $line?->delete();
                } elseif ($line) {
                    $line->update(['amount' => Money::amount(-$new)]);
                } else {
                    StudentFeeItem::create(['student_fee_id' => $fee->id, 'kind' => 'discount', 'description' => __('finance.discount_line'), 'amount' => Money::amount(-$new)]);
                }
                $fee->update(['discount' => Money::amount($new)]);
            }

            return $fee->load(['installments', 'items']);
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
