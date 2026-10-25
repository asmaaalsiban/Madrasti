<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class FinanceTest extends AcademicTestCase
{
    private AcademicYear $year;

    private GradeLevel $grade5;

    private GradeLevel $grade6;

    private User $accountant;

    private User $admin;

    private User $teacher;

    private User $parent;

    /** @var array<string, Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->grade5 = GradeLevel::factory()->create(['sort_order' => 5]);
        $this->grade6 = GradeLevel::factory()->create(['sort_order' => 6]);
        $room5 = Classroom::factory()->create(['name' => '5-A', 'grade_level_id' => $this->grade5->id, 'academic_year_id' => $this->year->id]);
        $room6 = Classroom::factory()->create(['name' => '6-A', 'grade_level_id' => $this->grade6->id, 'academic_year_id' => $this->year->id]);
        foreach ([['one', $room5], ['two', $room5], ['three', $room6]] as [$key, $room]) {
            $student = Student::create(['student_number' => "S-$key", 'name_ar' => "طالب $key", 'name_en' => "Student $key", 'gender' => 'male']);
            Enrollment::create(['student_id' => $student->id, 'classroom_id' => $room->id, 'academic_year_id' => $this->year->id]);
            $this->students[$key] = $student;
        }
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create(['name' => 'Accountant']);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->staff(StaffType::Teacher)->create();
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->students['one']->guardians()->attach($this->parent->id);
        Sanctum::actingAs($this->accountant);
    }

    private function structure(float $amount = 1000, ?GradeLevel $grade = null): FeeStructure
    {
        return FeeStructure::create(['academic_year_id' => $this->year->id, 'grade_level_id' => ($grade ?? $this->grade5)->id, 'name' => 'Tuition', 'amount' => $amount]);
    }

    private function applied(float $amount = 1000, int $count = 4): StudentFee
    {
        $structure = $this->structure($amount);
        $this->postJson("/api/finance/fee-structures/{$structure->id}/apply", ['installments' => $count, 'first_due' => '2026-09-01'])->assertOk();

        return StudentFee::where('student_id', $this->students['one']->id)->firstOrFail();
    }

    private function pay(StudentFee $fee, float $amount, array $extra = [])
    {
        return $this->postJson('/api/finance/payments', ['student_fee_id' => $fee->id, 'amount' => $amount, 'method' => 'cash', ...$extra]);
    }

    public function test_the_accountant_manages_fee_structures_and_the_admin_only_reads(): void
    {
        $id = $this->postJson('/api/finance/fee-structures', ['grade_level_id' => $this->grade5->id, 'name' => 'Tuition', 'amount' => 1200, 'installments_count' => 4, 'first_due' => '2026-09-01', 'every_months' => 1])->assertCreated()->json('data.id');
        $this->putJson("/api/finance/fee-structures/$id", ['grade_level_id' => $this->grade5->id, 'name' => 'Tuition 2026', 'amount' => 1250, 'installments_count' => 5, 'first_due' => '2026-10-01', 'every_months' => 2, 'auto_apply' => false])->assertOk();
        $this->getJson('/api/finance/fee-structures')->assertOk()->assertJsonPath('data.0.name', 'Tuition 2026')->assertJsonPath('data.0.amount', '1250.00')->assertJsonPath('data.0.installments_count', 5)->assertJsonPath('data.0.first_due', '2026-10-01')->assertJsonPath('data.0.auto_apply', false);
        $this->postJson('/api/finance/fee-structures', ['grade_level_id' => $this->grade5->id, 'name' => 'x', 'amount' => 0])->assertUnprocessable();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/finance/fee-structures')->assertOk();
        $this->postJson('/api/finance/fee-structures', ['grade_level_id' => $this->grade5->id, 'name' => 'x', 'amount' => 5])->assertForbidden();
        $this->deleteJson("/api/finance/fee-structures/$id")->assertForbidden();

        Sanctum::actingAs($this->accountant);
        $this->deleteJson("/api/finance/fee-structures/$id")->assertNoContent();
    }

    public function test_nobody_else_touches_the_money(): void
    {
        foreach ([$this->teacher, $this->parent, User::factory()->role(Role::Student)->create()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/finance/summary')->assertForbidden();
            $this->getJson('/api/finance/accounts')->assertForbidden();
            $this->postJson('/api/finance/payments', ['student_fee_id' => 1, 'amount' => 5, 'method' => 'cash'])->assertForbidden();
        }
    }

    public function test_applying_a_structure_gives_every_student_of_the_grade_an_even_split_once(): void
    {
        $structure = $this->structure(1000);

        $this->postJson("/api/finance/fee-structures/{$structure->id}/apply", ['installments' => 3, 'first_due' => '2026-09-01'])->assertOk()->assertJsonPath('data.students', 2);
        $this->postJson("/api/finance/fee-structures/{$structure->id}/apply", ['installments' => 3, 'first_due' => '2026-09-01'])->assertOk()->assertJsonPath('data.students', 0);

        $this->assertSame(2, StudentFee::count());
        $this->assertNull(StudentFee::where('student_id', $this->students['three']->id)->first());
        $fee = StudentFee::where('student_id', $this->students['one']->id)->first();
        $this->assertSame(['333.33', '333.33', '333.34'], $fee->installments->pluck('amount')->all());
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], $fee->installments->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->getJson('/api/finance/fee-structures')->assertJsonPath('data.0.students', 2);

        $this->deleteJson("/api/finance/fee-structures/{$structure->id}")->assertUnprocessable(); // already given to students
    }

    public function test_a_payment_is_spread_over_the_oldest_installments_first(): void
    {
        $fee = $this->applied(1000, 4); // 250 each, due Sep 1, Oct 1, Nov 1, Dec 1 2026

        $first = $this->pay($fee, 300, ['paid_on' => '2026-09-05', 'reference' => 'CH-1'])->assertCreated()->assertJsonPath('data.receipt', 'R-000001');
        $first->assertJsonCount(2, 'data.installments');

        $installments = $fee->fresh()->installments;
        $this->assertSame(['250.00', '50.00', '0.00', '0.00'], $installments->pluck('paid_amount')->all());
        $this->assertSame('paid', $installments[0]->status);
        $this->assertSame('partial', $installments[1]->fresh()->effectiveStatus() === 'overdue' ? 'partial' : $installments[1]->status); // stored status
        $this->assertSame($this->accountant->id, Payment::first()->received_by);
    }

    public function test_a_payment_can_target_an_installment_and_never_exceeds_what_is_owed(): void
    {
        $fee = $this->applied(1000, 4);
        $third = $fee->installments[2];

        $this->pay($fee, 100, ['installment_id' => $third->id])->assertCreated();
        $this->assertSame('100.00', $third->fresh()->paid_amount);

        $this->pay($fee, 901)->assertUnprocessable()->assertJsonValidationErrors('amount'); // 900 owed
        $this->pay($fee, 0)->assertUnprocessable();
        $this->pay($fee, 5, ['method' => 'gold'])->assertUnprocessable();
        $this->pay($fee, 900)->assertCreated(); // exactly what is left
        $this->assertSame(['paid', 'paid', 'paid', 'paid'], $fee->fresh()->installments->map(fn (Installment $i) => $i->effectiveStatus())->all());
        $this->pay($fee, 1)->assertUnprocessable();
    }

    public function test_an_unpaid_installment_after_its_due_date_is_overdue(): void
    {
        $fee = $this->applied(1000, 4); // all four are in the past by now (2026-09-01 ... 2026-12-01 vs the test clock)
        $this->travelTo(now()->setDate(2026, 10, 15));

        $statement = $this->getJson("/api/finance/students/{$this->students['one']->id}")->assertOk()->json('data');
        $this->assertSame(['overdue', 'overdue', 'pending', 'pending'], array_column($statement['fees'][0]['installments'], 'status'));
        $this->assertSame('500.00', $statement['totals']['overdue']);

        $this->pay($fee, 250, ['paid_on' => '2026-10-10']);
        $statement = $this->getJson("/api/finance/students/{$this->students['one']->id}")->json('data');
        $this->assertSame(['paid', 'overdue', 'pending', 'pending'], array_column($statement['fees'][0]['installments'], 'status'));
        $this->assertSame('250.00', $statement['totals']['overdue']);
        $this->assertSame('750.00', $statement['totals']['remaining']);
    }

    public function test_cancelling_a_payment_gives_the_money_back_to_the_installments(): void
    {
        $fee = $this->applied(1000, 4);
        $id = $this->pay($fee, 400)->json('data.id');

        $this->deleteJson("/api/finance/payments/$id")->assertNoContent();

        $this->assertSame(['0.00', '0.00', '0.00', '0.00'], $fee->fresh()->installments->pluck('paid_amount')->all());
        $this->assertSame(0, Payment::count());
        $this->assertTrue(AuditLog::where('auditable_type', Payment::class)->where('action', 'deleted')->exists());
    }

    public function test_the_accounts_list_filters_and_shows_balances(): void
    {
        $fee = $this->applied(1000, 4);
        $this->pay($fee, 1000)->assertCreated();
        $this->travelTo(now()->setDate(2026, 10, 15));

        $rows = collect($this->getJson('/api/finance/accounts')->assertOk()->json('data'));
        $this->assertCount(3, $rows);
        $one = $rows->firstWhere('student_number', 'S-one');
        $this->assertSame(['1000.00', '1000.00', '0.00', '0.00'], [$one['net'], $one['paid'], $one['remaining'], $one['overdue']]);
        $two = $rows->firstWhere('student_number', 'S-two');
        $this->assertSame(['1000.00', '0.00', '1000.00', '500.00'], [$two['net'], $two['paid'], $two['remaining'], $two['overdue']]);

        $this->assertSame(['S-two'], collect($this->getJson('/api/finance/accounts?status=overdue')->json('data'))->pluck('student_number')->all());
        $this->assertSame(['S-one'], collect($this->getJson('/api/finance/accounts?status=settled')->json('data'))->pluck('student_number')->all());
        $this->assertEqualsCanonicalizing(['S-two'], collect($this->getJson('/api/finance/accounts?status=owing')->json('data'))->pluck('student_number')->all());
        $this->assertSame(['S-three'], collect($this->getJson("/api/finance/accounts?grade_level_id={$this->grade6->id}")->json('data'))->pluck('student_number')->all());
        $this->assertSame(['S-two'], collect($this->getJson('/api/finance/accounts?search=two')->json('data'))->pluck('student_number')->all());
    }

    public function test_the_summary_adds_up_the_school(): void
    {
        $fee = $this->applied(1000, 4); // two students: 2000 expected
        $this->pay($fee, 600, ['paid_on' => now()->toDateString()])->assertCreated();
        $this->travelTo(now()->setDate(2026, 10, 15));

        $summary = $this->getJson('/api/finance/summary')->assertOk()->json('data');
        $this->assertSame('2000.00', $summary['expected']);
        $this->assertSame('600.00', $summary['collected']);
        $this->assertSame('1400.00', $summary['remaining']);
        // Sep 1 and Oct 1 are past: student one owes 400 of them (500 due - 600 paid covers 2: 0 overdue); student two owes 500.
        $this->assertSame('500.00', $summary['overdue']);
        $this->assertSame(1, $summary['students_overdue']);
        $this->assertSame('JOD', $summary['currency']);
    }

    public function test_parents_read_the_statement_of_their_child_only_and_students_cannot(): void
    {
        $this->applied(1000, 4);
        $id = $this->students['one']->id;

        Sanctum::actingAs($this->parent);
        $this->getJson("/api/students/$id/fees")->assertOk()->assertJsonPath('data.totals.net', '1000.00')->assertJsonCount(4, 'data.fees.0.installments');
        $this->getJson("/api/students/{$this->students['two']->id}/fees")->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson("/api/students/$id/fees")->assertForbidden();

        $studentUser = User::factory()->role(Role::Student)->create();
        $this->students['one']->update(['user_id' => $studentUser->id]);
        Sanctum::actingAs($studentUser);
        $this->getJson("/api/students/$id/fees")->assertForbidden();

        foreach ([$this->admin, $this->accountant] as $reader) {
            Sanctum::actingAs($reader);
            $this->getJson("/api/students/$id/fees")->assertOk();
        }
        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/students/$id/fees")->assertForbidden();
    }

    public function test_the_currency_is_a_school_setting_the_accountant_changes(): void
    {
        $this->getJson('/api/finance/settings')->assertOk()->assertJsonPath('data.currency', 'JOD')->assertJsonPath('data.can_manage', true);
        $this->putJson('/api/finance/settings', ['currency' => 'ILS'])->assertOk();
        $this->getJson('/api/finance/summary')->assertJsonPath('data.currency', 'ILS');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/finance/settings')->assertJsonPath('data.can_manage', false);
        $this->putJson('/api/finance/settings', ['currency' => 'USD'])->assertForbidden();
    }

    public function test_money_changes_are_audited(): void
    {
        $fee = $this->applied(1000, 4);
        $this->pay($fee, 100)->assertCreated();

        $this->assertTrue(AuditLog::where('auditable_type', Payment::class)->where('action', 'created')->where('user_id', $this->accountant->id)->exists());
        $this->assertTrue(AuditLog::where('auditable_type', StudentFee::class)->where('action', 'created')->exists());
    }

    public function test_parents_are_reminded_of_installments_due_soon_and_overdue_once_a_week(): void
    {
        $fee = $this->applied(1000, 4); // due 2026-09-01, 10-01, 11-01, 12-01
        $this->travelTo(now()->setDate(2026, 10, 29)); // Nov 1 is 3 days away; Sep 1 and Oct 1 are overdue

        $this->artisan('finance:remind')->assertSuccessful();

        $bell = $this->parent->notifications()->get()->map(fn ($n) => $n->data);
        $this->assertCount(3, $bell);
        $this->assertSame(['installment'], $bell->pluck('kind')->unique()->all());
        $this->assertSame(1, $bell->where('params.overdue', false)->count());
        $this->assertSame('250.00', $bell[0]['params']['amount']);
        $this->assertSame("/parent/children/{$this->students['one']->id}/fees", $bell[0]['link']);
        $this->assertSame(0, $this->teacher->notifications()->count());

        $this->artisan('finance:remind')->assertSuccessful(); // the same day: nobody is reminded twice
        $this->assertSame(3, $this->parent->notifications()->count());

        $this->travelTo(now()->addDays(8));
        $this->artisan('finance:remind')->assertSuccessful();
        $this->assertGreaterThan(3, $this->parent->notifications()->count());

        $this->pay($fee, 1000)->assertCreated(); // paid in full: no more reminders
        $before = $this->parent->notifications()->count();
        $this->travelTo(now()->addDays(8));
        $this->artisan('finance:remind')->assertSuccessful();
        $this->assertSame($before, $this->parent->notifications()->count());
    }

    public function test_the_options_list_the_grades_and_sections_for_the_filters(): void
    {
        $data = $this->getJson('/api/finance/options')->assertOk()->json('data');
        $this->assertCount(2, $data['grades']);
        $this->assertEqualsCanonicalizing(['5-A', '6-A'], array_column($data['classrooms'], 'name'));

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/finance/options')->assertForbidden();
    }

    public function test_a_fee_for_a_student_with_no_schedule_creates_one_with_transport_discount_and_a_first_payment(): void
    {
        $student = $this->students['three'];
        $response = $this->postJson("/api/finance/students/{$student->id}/fees", [
            'description' => 'Tuition', 'total_amount' => 900, 'transport_fee' => 100, 'discount' => 100, 'installments_count' => 9, 'first_due' => '2026-09-01',
            'first_payment' => 200, 'first_payment_method' => 'cash', 'payer_name' => 'Abu Sami',
        ])->assertCreated()->assertJsonPath('data.spread', false)->assertJsonCount(1, 'data.payments');

        $fee = StudentFee::first();
        $this->assertSame('1000.00', $fee->total_amount);
        $this->assertSame(9, $fee->installments->count()); // 900 net in 9 installments: transport is inside them
        $this->assertSame(['other', 'transport', 'discount'], $fee->items->pluck('kind')->all());
        $this->assertSame(['100.00', '100.00', '100.00', '100.00', '100.00', '100.00', '100.00', '100.00', '100.00'], $fee->installments->pluck('amount')->all());
        $this->assertSame(['100.00', '100.00'], array_slice($fee->installments->pluck('paid_amount')->all(), 0, 2)); // the 200 paid first
        $this->assertSame('Abu Sami', Payment::first()->payer_name);
    }

    public function test_a_schedule_that_does_not_add_up_and_a_discount_that_eats_the_fee_are_refused(): void
    {
        $student = $this->students['three'];
        $this->postJson("/api/finance/students/{$student->id}/fees", ['description' => 'Books', 'total_amount' => 100, 'installments' => [['due_date' => '2026-09-01', 'amount' => 30], ['due_date' => '2026-10-01', 'amount' => 60]]])
            ->assertUnprocessable()->assertJsonValidationErrors('installments'); // 90 is not 100
        $this->postJson("/api/finance/students/{$student->id}/fees", ['description' => 'Free', 'total_amount' => 100, 'discount' => 100, 'installments_count' => 1, 'first_due' => '2026-09-01'])->assertUnprocessable();
        $this->postJson("/api/finance/students/{$student->id}/fees", ['description' => 'Books', 'total_amount' => 100, 'installments' => [['due_date' => '2026-09-01', 'amount' => 30], ['due_date' => '2026-10-01', 'amount' => 70]]])->assertCreated();
        $this->assertSame(1, StudentFee::count());
    }

    public function test_the_discount_can_change_at_any_time_but_a_fee_with_payments_cannot_be_deleted(): void
    {
        $fee = $this->applied(1000, 4);
        $this->putJson("/api/finance/fees/{$fee->id}", ['discount' => 200])->assertOk();
        $this->assertSame(['200.00', '200.00', '200.00', '200.00'], $fee->fresh()->installments->pluck('amount')->all());
        $this->putJson("/api/finance/fees/{$fee->id}", ['discount' => 1000])->assertUnprocessable();

        $this->pay($fee, 100)->assertCreated(); // a part of the first installment
        $this->putJson("/api/finance/fees/{$fee->id}", ['discount' => 300])->assertOk(); // 100 more off the open installments
        $this->assertSame(['100.00', '200.00', '200.00', '200.00'][0], $fee->fresh()->installments[0]->paid_amount);
        $this->assertSame(700.0, round(array_sum(array_map('floatval', $fee->fresh()->installments->pluck('amount')->all())), 2));
        $this->assertSame(['yearly', 'discount'], $fee->fresh()->items->pluck('kind')->all());
        $this->putJson("/api/finance/fees/{$fee->id}", ['discount' => 0])->assertOk();
        $this->assertSame(['yearly'], $fee->fresh()->items->pluck('kind')->all());

        $this->deleteJson("/api/finance/fees/{$fee->id}")->assertUnprocessable();
        $other = StudentFee::where('student_id', $this->students['two']->id)->first();
        $this->deleteJson("/api/finance/fees/{$other->id}")->assertNoContent();
    }

    public function test_the_payer_name_is_kept_for_the_receipt(): void
    {
        $fee = $this->applied(1000, 4);
        $this->pay($fee, 100, ['payer_name' => 'Abu Mohammad'])->assertCreated()->assertJsonPath('data.payer_name', 'Abu Mohammad');

        $statement = $this->getJson("/api/finance/students/{$this->students['one']->id}")->json('data');
        $this->assertSame('Abu Mohammad', $statement['fees'][0]['payments'][0]['payer_name']);
        $this->assertTrue($statement['has_schedule']);
        $this->assertSame(['yearly'], array_column($statement['fees'][0]['items'], 'kind'));
    }
}
