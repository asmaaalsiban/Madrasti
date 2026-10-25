<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\StudentFee;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class AutoFeesTest extends AcademicTestCase
{
    private AcademicYear $year;

    private Classroom $first;

    private Classroom $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $g1 = GradeLevel::factory()->create(['sort_order' => 1]);
        $g2 = GradeLevel::factory()->create(['sort_order' => 2]);
        $g3 = GradeLevel::factory()->create(['sort_order' => 3]);
        $this->first = Classroom::factory()->create(['name' => '1-A', 'grade_level_id' => $g1->id, 'academic_year_id' => $this->year->id]);
        $this->second = Classroom::factory()->create(['name' => '2-A', 'grade_level_id' => $g2->id, 'academic_year_id' => $this->year->id]);
        Classroom::factory()->create(['name' => '3-A', 'grade_level_id' => $g3->id, 'academic_year_id' => $this->year->id]);
        foreach ([[$g1, 700], [$g2, 800]] as [$grade, $amount]) {
            FeeStructure::create(['academic_year_id' => $this->year->id, 'grade_level_id' => $grade->id, 'name' => 'Tuition', 'amount' => $amount, 'installments_count' => 4, 'first_due' => '2026-09-01', 'every_months' => 1, 'auto_apply' => true]);
        }
        $this->actAsAdmin();
    }

    private function enrol(Classroom $room, array $extra = [])
    {
        static $n = 0;
        $n++;

        return $this->postJson('/api/students', ['student_number' => "S-$n", 'name_ar' => "طالب $n", 'name_en' => "Student $n", 'classroom_id' => $room->id, ...$extra]);
    }

    public function test_a_new_student_gets_the_yearly_fee_of_their_grade_split_by_its_plan(): void
    {
        $response = $this->enrol($this->first)->assertCreated();

        $response->assertJsonPath('data.created_fees.0.description', 'Tuition')->assertJsonPath('data.created_fees.0.net', '700.00')->assertJsonPath('data.created_fees.0.installments', 4);
        $fee = StudentFee::first();
        $this->assertSame(['175.00', '175.00', '175.00', '175.00'], $fee->installments->pluck('amount')->all());
        $this->assertSame('2026-09-01', $fee->installments->first()->due_date->toDateString());

        $this->enrol($this->second)->assertCreated()->assertJsonPath('data.created_fees.0.net', '800.00'); // each grade has its own price
    }

    public function test_a_discount_in_money_comes_off_the_yearly_fee(): void
    {
        $this->enrol($this->first, ['fee_discount' => 100])->assertCreated()->assertJsonPath('data.created_fees.0.net', '600.00');

        $fee = StudentFee::first();
        $this->assertSame('100.00', $fee->discount);
        $this->assertSame(['150.00', '150.00', '150.00', '150.00'], $fee->installments->pluck('amount')->all());
    }

    public function test_a_transport_fee_is_added_for_that_student_only(): void
    {
        $this->enrol($this->first, ['transport_fee' => 200])->assertCreated()->assertJsonCount(2, 'data.created_fees');
        $this->enrol($this->first)->assertCreated()->assertJsonCount(1, 'data.created_fees'); // the next student has none

        $bus = StudentFee::where('description', 'Transport')->first();
        $this->assertSame('200.00', $bus->total_amount);
        $this->assertSame(['50.00', '50.00', '50.00', '50.00'], $bus->installments->pluck('amount')->all()); // by the grade's plan
        $this->assertSame(1, StudentFee::where('description', 'Transport')->count());
    }

    public function test_a_discount_larger_than_the_fee_is_refused_and_nothing_is_created(): void
    {
        $this->enrol($this->first, ['fee_discount' => 700])->assertUnprocessable()->assertJsonValidationErrors('fee_discount');

        $this->assertSame(0, Student::count());
        $this->assertSame(0, StudentFee::count());
    }

    public function test_a_grade_without_a_yearly_fee_gets_nothing_and_a_discount_there_is_refused(): void
    {
        $third = Classroom::where('name', '3-A')->first();
        $this->enrol($third)->assertCreated()->assertJsonCount(0, 'data.created_fees');
        $this->enrol($third, ['fee_discount' => 50])->assertUnprocessable();
        $this->assertSame(0, StudentFee::count());
    }

    public function test_a_fee_that_is_not_automatic_is_left_for_the_accountant(): void
    {
        FeeStructure::query()->update(['auto_apply' => false]);

        $this->enrol($this->first)->assertCreated()->assertJsonCount(0, 'data.created_fees');
        $this->assertSame(0, StudentFee::count());
    }

    public function test_moving_a_student_to_another_grade_adds_that_grades_fee_and_keeps_what_they_have(): void
    {
        $id = $this->enrol($this->first)->json('data.id');
        $this->assertSame(['700.00'], StudentFee::pluck('total_amount')->all());

        $this->putJson("/api/students/$id", ['student_number' => 'S-1', 'name_ar' => 'طالب', 'classroom_id' => $this->second->id])->assertOk();
        $this->putJson("/api/students/$id", ['student_number' => 'S-1', 'name_ar' => 'طالب', 'classroom_id' => $this->second->id])->assertOk(); // saving again adds nothing

        $this->assertEqualsCanonicalizing(['700.00', '800.00'], StudentFee::pluck('total_amount')->all());
    }

    public function test_the_admins_student_list_shows_what_each_student_owes_and_what_remains(): void
    {
        $id = $this->enrol($this->first)->json('data.id');
        $paidFee = StudentFee::first();
        $paidFee->installments->first()->update(['paid_amount' => 175]);

        $rows = collect($this->getJson('/api/students')->assertOk()->json('data'));
        $this->assertSame(['net' => '700.00', 'paid' => '175.00', 'remaining' => '525.00', 'overdue' => '175.00'], $rows->firstWhere('id', $id)['balance'] + []);
    }

    public function test_the_yearly_fee_form_needs_a_plan_and_giving_it_uses_that_plan(): void
    {
        $accountant = User::factory()->staff(StaffType::Accountant)->create();
        $other = Classroom::where('name', '3-A')->first();
        Sanctum::actingAs($accountant);

        $this->postJson('/api/finance/fee-structures', ['grade_level_id' => $other->grade_level_id, 'name' => 'Tuition', 'amount' => 900])->assertUnprocessable();
        $id = $this->postJson('/api/finance/fee-structures', ['grade_level_id' => $other->grade_level_id, 'name' => 'Tuition', 'amount' => 900, 'installments_count' => 3, 'first_due' => '2026-09-01', 'every_months' => 1, 'auto_apply' => false])->assertCreated()->json('data.id');

        $student = Student::create(['student_number' => 'late', 'name_ar' => 'متأخر', 'name_en' => 'Late', 'gender' => 'male']);
        Enrollment::create(['student_id' => $student->id, 'classroom_id' => $other->id, 'academic_year_id' => $this->year->id]);
        $this->postJson("/api/finance/fee-structures/$id/apply")->assertOk()->assertJsonPath('data.students', 1); // no options: the fee's own plan

        $this->assertSame(['300.00', '300.00', '300.00'], StudentFee::where('student_id', $student->id)->first()->installments->pluck('amount')->all());
    }
}
