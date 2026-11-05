<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\FeeCarryover;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Finance\Services\AccountReader;
use App\Modules\Finance\Services\FeeBilling;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class YearEndTest extends AcademicTestCase
{
    private AcademicYear $old;

    private AcademicYear $new;

    private User $accountant;

    private Student $sara;

    private Student $omar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->old = AcademicYear::factory()->create(['name' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-06-30', 'is_current' => true]);
        $this->new = AcademicYear::factory()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => false]);
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create();
        $this->sara = Student::create(['student_number' => 'S-1', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'gender' => 'female']);
        $this->omar = Student::create(['student_number' => 'S-2', 'name_ar' => 'عمر', 'name_en' => 'Omar', 'gender' => 'male']);
        Sanctum::actingAs($this->accountant);
    }

    /** A yearly fee of 1000 in 4 installments, with `$paid` already paid. */
    private function fee(Student $s, int $paid): StudentFee
    {
        $billing = app(FeeBilling::class);
        $fee = $billing->createFee($s, $this->old, 'Tuition', '1000.00', '0', $billing->evenSchedule(100000, 4, '2025-09-01'), null, 'yearly');
        if ($paid > 0) {
            $billing->recordPayment($fee, number_format($paid, 2, '.', ''), 'cash', '2025-09-02', $this->accountant);
        }

        return $fee->refresh();
    }

    private function carryOver(array $extra = [])
    {
        return $this->postJson('/api/finance/year-end', ['from_year_id' => $this->old->id, 'to_year_id' => $this->new->id, 'first_due' => '2026-09-15', 'installments' => 3, ...$extra]);
    }

    public function test_the_preview_lists_only_students_who_still_owe_with_the_total(): void
    {
        $this->fee($this->sara, 400);
        $this->fee($this->omar, 1000); // paid in full

        $this->getJson('/api/finance/year-end?from='.$this->old->id)->assertOk()
            ->assertJsonCount(1, 'data.students')->assertJsonPath('data.students.0.student_number', 'S-1')->assertJsonPath('data.students.0.remaining', '600.00')
            ->assertJsonPath('data.total', '600.00')->assertJsonPath('data.can_manage', true);
    }

    public function test_the_balance_moves_to_the_new_year_in_installments_and_the_old_year_is_closed(): void
    {
        $this->fee($this->sara, 400);

        $this->carryOver()->assertOk()->assertJsonPath('data.carried', 1)->assertJsonPath('data.total', '600.00');

        $carried = StudentFee::where('academic_year_id', $this->new->id)->first();
        $this->assertSame('carried', $carried->kind);
        $this->assertEqualsWithDelta(600.0, (float) $carried->total_amount, 0.001);
        $this->assertSame(['200.00', '200.00', '200.00'], $carried->installments->pluck('amount')->all());
        $this->assertSame('2026-09-15', $carried->installments->first()->due_date->toDateString());

        $this->assertSame(0, app(FeeBilling::class)->remainingCents(StudentFee::where('academic_year_id', $this->old->id)->first())); // nothing left to pay for the old year
        $this->assertSame('carry_forward', Payment::orderByDesc('id')->first()->method);
        $this->assertSame(1, FeeCarryover::count());
        $this->getJson('/api/finance/year-end?from='.$this->old->id)->assertJsonCount(0, 'data.students'); // so a second run finds nothing
        $this->carryOver()->assertOk()->assertJsonPath('data.carried', 0);
        $this->assertSame(1, StudentFee::where('academic_year_id', $this->new->id)->count());
    }

    public function test_carried_money_is_never_counted_as_collected(): void
    {
        $this->fee($this->sara, 400);
        $this->carryOver()->assertOk();
        $reader = app(AccountReader::class);

        $old = $reader->summary($this->old);
        $this->assertSame('400.00', $old['collected']);   // cash only
        $this->assertSame('600.00', $old['carried_out']);
        $this->assertSame('0.00', $old['remaining']);
        $new = $reader->summary($this->new);
        $this->assertSame('600.00', $new['expected']);
        $this->assertSame('600.00', $new['carried_in']);
        $this->assertSame('0.00', $new['collected']);
        $this->assertSame('600.00', $new['remaining']);

        $this->getJson('/api/admin/dashboard')->assertForbidden(); // the principal's page is separate: checked below as admin
        $this->actAsAdmin();
        $this->assertEqualsWithDelta(400.0, $this->getJson('/api/admin/dashboard')->json('data.finance.collected'), 0.001);
    }

    public function test_only_the_chosen_students_move_and_the_statement_runs_on(): void
    {
        $this->fee($this->sara, 0);
        $this->fee($this->omar, 250);

        $this->carryOver(['student_ids' => [$this->omar->id]])->assertOk()->assertJsonPath('data.carried', 1)->assertJsonPath('data.total', '750.00');

        $this->assertSame(0, StudentFee::where('student_id', $this->sara->id)->where('academic_year_id', $this->new->id)->count());
        $statement = $this->getJson("/api/finance/students/{$this->omar->id}")->assertOk()->json('data');
        $this->assertCount(2, $statement['fees']); // the old fee and the carried one, in one statement
        $this->assertContains('carried', array_column($statement['fees'], 'kind'));
        // Omar paid 250 in cash and 750 moved on: "paid" stays the cash, the carried part is shown apart, and the remaining is what is really still owed.
        $this->assertSame(['net' => '1750.00', 'paid' => '250.00', 'carried' => '750.00', 'remaining' => '750.00'], array_intersect_key($statement['totals'], array_flip(['net', 'paid', 'carried', 'remaining'])));
    }

    public function test_a_move_can_be_undone_until_something_is_paid_on_it(): void
    {
        $this->fee($this->sara, 400);
        $this->carryOver()->assertOk();
        $move = FeeCarryover::first();

        $this->postJson("/api/finance/year-end/{$move->id}/undo")->assertOk();
        $this->assertSame(60000, app(FeeBilling::class)->remainingCents(StudentFee::where('academic_year_id', $this->old->id)->first()));
        $this->assertSame(0, StudentFee::where('academic_year_id', $this->new->id)->count());
        $this->assertNotNull($move->refresh()->reverted_at);
        $this->postJson("/api/finance/year-end/{$move->id}/undo")->assertUnprocessable(); // not twice

        // Move again, then take a payment on the carried balance: now the move stays.
        $this->carryOver()->assertOk();
        $second = FeeCarryover::whereNull('reverted_at')->first();
        app(FeeBilling::class)->recordPayment(StudentFee::find($second->new_fee_id), '100.00', 'cash', '2026-09-20', $this->accountant);
        $this->postJson("/api/finance/year-end/{$second->id}/undo")->assertUnprocessable();
        $this->assertTrue(Installment::count() > 0);
    }

    public function test_a_carry_forward_entry_cannot_be_cancelled_as_a_normal_payment(): void
    {
        $this->fee($this->sara, 0);
        $this->carryOver()->assertOk();

        $this->deleteJson('/api/finance/payments/'.Payment::where('method', 'carry_forward')->value('id'))->assertUnprocessable();
    }

    public function test_the_new_year_must_come_after_the_old_one_and_only_the_accountant_can_move(): void
    {
        $this->fee($this->sara, 0);
        $this->carryOver(['to_year_id' => $this->old->id])->assertUnprocessable();
        $this->postJson('/api/finance/year-end', ['from_year_id' => $this->new->id, 'to_year_id' => $this->old->id, 'first_due' => '2026-09-15', 'installments' => 3])->assertUnprocessable();
        $this->carryOver(['installments' => 0])->assertUnprocessable();

        $this->actAsAdmin(); // the principal reads but does not change the money
        $this->getJson('/api/finance/year-end')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->carryOver()->assertForbidden();
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson('/api/finance/year-end')->assertForbidden();
    }
}
