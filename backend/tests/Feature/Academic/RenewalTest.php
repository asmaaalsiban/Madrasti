<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\StudentRenewal;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\StudentFee;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class RenewalTest extends AcademicTestCase
{
    private AcademicYear $now;

    private AcademicYear $next;

    private GradeLevel $g1;

    private GradeLevel $g2;

    private GradeLevel $g3;

    private Classroom $oldA;

    private Classroom $oldB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = AcademicYear::factory()->create(['name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        $this->next = AcademicYear::factory()->create(['name' => '2027/2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30', 'is_current' => false]);
        $this->g1 = GradeLevel::factory()->create(["sort_order" => 1]);
        $this->g2 = GradeLevel::factory()->create(["sort_order" => 2]);
        $this->g3 = GradeLevel::factory()->create(["sort_order" => 3]);
        $this->oldA = Classroom::factory()->create(['name' => '1-A', 'grade_level_id' => $this->g1->id, 'academic_year_id' => $this->now->id]);
        $this->oldB = Classroom::factory()->create(['name' => '3-A', 'grade_level_id' => $this->g3->id, 'academic_year_id' => $this->now->id]);
        $this->actAsAdmin();
    }

    private function student(string $name, Classroom $room, ?string $gender = null): Student
    {
        static $n = 0;
        $n++;
        $s = Student::create(['student_number' => "R-$n", 'name_ar' => $name, 'name_en' => $name, 'gender' => $gender]);
        Enrollment::create(['student_id' => $s->id, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id]);

        return $s;
    }

    private function newRooms(): array
    {
        return [
            Classroom::factory()->create(['name' => '2-A', 'grade_level_id' => $this->g2->id, 'academic_year_id' => $this->next->id]),
            Classroom::factory()->create(['name' => '2-B', 'grade_level_id' => $this->g2->id, 'academic_year_id' => $this->next->id]),
        ];
    }

    public function test_opening_a_year_lists_everyone_with_their_next_grade_and_the_last_grade_graduates(): void
    {
        $a = $this->student('Ali', $this->oldA);
        $b = $this->student('Basel', $this->oldB);
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id])->assertCreated()->assertJsonPath('added', 2);
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id])->assertCreated()->assertJsonPath('added', 0); // again adds nobody

        $this->assertSame('pending', StudentRenewal::where('student_id', $a->id)->first()->status);
        $this->assertSame($this->g2->id, StudentRenewal::where('student_id', $a->id)->first()->to_grade_level_id);
        $this->assertSame('graduating', StudentRenewal::where('student_id', $b->id)->first()->status);
        $this->getJson('/api/renewals')->assertOk()->assertJsonPath('year.name', '2027/2028')->assertJsonPath('summary.pending', 1)->assertJsonPath('summary.graduating', 1)->assertJsonPath('summary.total', 2);
    }

    public function test_a_student_deleted_after_the_renewal_was_opened_does_not_break_the_page(): void
    {
        $gone = $this->student('Gone', $this->oldA);
        $stays = $this->student('Stays', $this->oldA);
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id])->assertCreated()->assertJsonPath('added', 2);

        $this->deleteJson("/api/students/{$gone->id}")->assertNoContent(); // the school deletes a student: the row of the renewal stays

        $this->getJson('/api/renewals')->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student.id', $stays->id);
    }

    public function test_renewing_everyone_skips_a_student_the_school_deleted(): void
    {
        $gone = $this->student('Gone', $this->oldA);
        $stays = $this->student('Stays', $this->oldA);
        $this->newRooms();
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id])->assertCreated();
        $this->deleteJson("/api/students/{$gone->id}")->assertNoContent();

        $this->postJson('/api/renewals/renew-all', ['year_id' => $this->next->id])->assertOk()->assertJsonPath('renewed', 1)->assertJsonPath('failed', []);
        $this->assertSame('renewed', StudentRenewal::where('student_id', $stays->id)->value('status'));
    }

    public function test_the_year_must_come_after_the_current_one(): void
    {
        $this->postJson('/api/renewals/open', ['year_id' => $this->now->id])->assertUnprocessable();
        $old = AcademicYear::factory()->create(['starts_on' => '2020-09-01', 'ends_on' => '2021-06-30', 'is_current' => false]);
        $this->postJson('/api/renewals/open', ['year_id' => $old->id])->assertUnprocessable();
    }

    public function test_renewing_moves_the_student_to_the_next_grade_and_gives_the_new_fees(): void
    {
        [$roomA] = $this->newRooms();
        FeeStructure::create(['academic_year_id' => $this->next->id, 'grade_level_id' => $this->g2->id, 'name' => 'Tuition', 'amount' => 900, 'installments_count' => 3, 'first_due' => '2027-09-01', 'every_months' => 1, 'auto_apply' => true]);
        $s = $this->student('Ali', $this->oldA);
        $user = User::factory()->create(['role' => Role::Guardian]);
        $s->guardians()->attach($user->id);
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id]);
        $renewal = StudentRenewal::first();

        $this->postJson("/api/renewals/{$renewal->id}/renew", ['classroom_id' => $roomA->id])->assertOk()->assertJsonPath('fees_created', 1)->assertJsonPath('data.status', 'renewed')->assertJsonPath('data.to_classroom', '2-A');

        $this->assertSame($roomA->id, Enrollment::where('student_id', $s->id)->where('academic_year_id', $this->next->id)->value('classroom_id'));
        $this->assertSame($this->oldA->id, Enrollment::where('student_id', $s->id)->where('academic_year_id', $this->now->id)->value('classroom_id')); // last year stays
        $fee = StudentFee::where('student_id', $s->id)->where('academic_year_id', $this->next->id)->first();
        $this->assertSame('900.00', $fee->total_amount);
        $this->assertCount(3, $fee->installments);
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $user->id)->where('data', 'like', '%student_renewed%')->exists());
        $this->assertTrue(AuditLog::where('auditable_type', StudentRenewal::class)->exists());
        $this->postJson("/api/renewals/{$renewal->id}/renew")->assertUnprocessable(); // not twice
    }

    public function test_it_picks_the_emptiest_section_that_has_room_and_suits_the_gender(): void
    {
        [$roomA, $roomB] = $this->newRooms();
        $roomB->update(['gender' => 'female']);
        $roomA->update(['capacity' => 1]);
        $filler = $this->student('Filler', $this->oldA);
        Enrollment::create(['student_id' => $filler->id, 'classroom_id' => $roomA->id, 'academic_year_id' => $this->next->id]); // 2-A is full
        $girl = $this->student('Dana', $this->oldA, 'female');
        $boy = $this->student('Omar', $this->oldA, 'male');
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id]);

        $result = $this->postJson('/api/renewals/renew-all', ['year_id' => $this->next->id])->assertOk()->json();
        $this->assertSame(1, $result['renewed']); // Dana; the filler was already placed (renewed on opening)
        $this->assertCount(1, $result['failed']); // Omar: 2-A is full and 2-B is for girls
        $this->assertSame('Omar', $result['failed'][0]['student']);
        $this->assertSame($roomB->id, Enrollment::where('student_id', $girl->id)->where('academic_year_id', $this->next->id)->value('classroom_id'));
        $this->assertSame('pending', StudentRenewal::where('student_id', $boy->id)->value('status'));
    }

    public function test_a_section_must_match_the_year_and_the_grade(): void
    {
        $this->newRooms();
        $wrong = Classroom::factory()->create(['name' => '1-X', 'grade_level_id' => $this->g1->id, 'academic_year_id' => $this->next->id]);
        $this->student('Ali', $this->oldA);
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id]);
        $this->postJson('/api/renewals/'.StudentRenewal::first()->id.'/renew', ['classroom_id' => $wrong->id])->assertUnprocessable();
        // Repeating the grade is allowed by choosing it.
        $this->postJson('/api/renewals/'.StudentRenewal::first()->id.'/renew', ['grade_level_id' => $this->g1->id, 'classroom_id' => $wrong->id])->assertOk()->assertJsonPath('data.to_grade.id', $this->g1->id);
    }

    public function test_the_last_grade_cannot_be_renewed_without_choosing_a_grade_but_can_be_declined_and_reopened(): void
    {
        $this->student('Basel', $this->oldB);
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id]);
        $id = StudentRenewal::first()->id;
        $this->postJson("/api/renewals/$id/renew")->assertUnprocessable();
        $this->postJson("/api/renewals/$id/decline", ['reason' => 'Moving abroad'])->assertOk()->assertJsonPath('data.status', 'declined')->assertJsonPath('data.decline_reason', 'Moving abroad');
        $this->assertSame(0, Enrollment::where('academic_year_id', $this->next->id)->count()); // a declined student gets no place
        $this->postJson("/api/renewals/$id/reopen")->assertOk()->assertJsonPath('data.status', 'graduating');
        $this->postJson("/api/renewals/$id/reopen")->assertUnprocessable();
    }

    public function test_sections_show_how_full_they_are(): void
    {
        [$roomA] = $this->newRooms();
        $roomA->update(['capacity' => 5]);
        $this->getJson("/api/renewals/sections?year_id={$this->next->id}&grade_level_id={$this->g2->id}")->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['name' => '2-A', 'students' => 0, 'capacity' => 5, 'free' => 5]);
    }

    public function test_only_management_and_the_accountant_can_run_it(): void
    {
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id])->assertCreated();
        Sanctum::actingAs(User::factory()->staff(StaffType::Accountant)->create());
        $this->getJson('/api/renewals')->assertOk();
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->getJson('/api/renewals')->assertForbidden();
        $this->postJson('/api/renewals/open', ['year_id' => $this->next->id])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Guardian]));
        $this->getJson('/api/renewals')->assertForbidden();
    }
}
