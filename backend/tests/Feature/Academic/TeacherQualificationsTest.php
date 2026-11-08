<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;

/** A teacher's profile keeps what they studied and have done, and shows how many periods they teach. */
class TeacherQualificationsTest extends AcademicTestCase
{
    /** @return array<string, mixed> */
    private function body(array $extra = []): array
    {
        return ['name' => 'Omar', 'email' => 'omar@x.test', 'staff_type' => 'teacher', 'degree' => 'master', 'specialization' => 'Curriculum', 'university' => 'Jerash', 'graduation_year' => 2019, 'experience_years' => 15, 'courses' => 'ICDL, first aid', ...$extra];
    }

    public function test_the_qualifications_are_saved_with_the_teacher_and_come_back(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/staff', $this->body())->assertCreated()
            ->assertJsonPath('data.degree', 'master')->assertJsonPath('data.specialization', 'Curriculum')->assertJsonPath('data.university', 'Jerash')
            ->assertJsonPath('data.graduation_year', 2019)->assertJsonPath('data.experience_years', 15)->assertJsonPath('data.courses', 'ICDL, first aid')->json('data.id');

        $this->putJson("/api/staff/$id", $this->body(['degree' => 'bachelor', 'courses' => null]))->assertOk()->assertJsonPath('data.degree', 'bachelor')->assertJsonPath('data.courses', null);
        $this->getJson('/api/staff')->assertOk()->assertJsonPath('data.0.university', 'Jerash');
    }

    public function test_the_qualifications_are_validated_and_every_kind_of_staff_can_have_them(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/staff', $this->body(['graduation_year' => 1800]))->assertStatus(422)->assertJsonValidationErrors('graduation_year');
        $this->postJson('/api/staff', $this->body(['experience_years' => 80, 'email' => 'b@x.test']))->assertStatus(422)->assertJsonValidationErrors('experience_years');
        $this->postJson('/api/staff', $this->body(['staff_type' => 'nurse', 'email' => 'n@x.test', 'degree' => 'bachelor', 'specialization' => 'Nursing']))->assertCreated()->assertJsonPath('data.specialization', 'Nursing');
    }

    public function test_the_staff_list_shows_the_periods_each_teacher_is_assigned_this_year(): void
    {
        $this->actAsAdmin();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $old = AcademicYear::factory()->create(['is_current' => false]);
        $grade = GradeLevel::factory()->create();
        $math = Subject::factory()->create();
        $id = $this->postJson('/api/staff', $this->body(['subject_id' => $math->id]))->json('data.id');
        foreach ([[$year, 5], [$year, 4], [$old, 9]] as $i => [$y, $periods]) {
            $room = Classroom::factory()->create(['name' => "R$i", 'grade_level_id' => $grade->id, 'academic_year_id' => $y->id]);
            TeachingAssignment::create(['classroom_id' => $room->id, 'subject_id' => $math->id, 'teacher_id' => $id, 'weekly_periods' => $periods]);
        }
        $this->getJson('/api/staff')->assertOk()->assertJsonPath('data.0.weekly_load', 9)->assertJsonPath('data.0.max_load', 24); // last year's 9 periods do not count

        $this->postJson('/api/staff', $this->body(['email' => 'new@x.test', 'name' => 'Zed']))->assertCreated();
        $this->postJson('/api/staff', $this->body(['email' => 'nurse@x.test', 'name' => 'Nora', 'staff_type' => 'nurse']))->assertCreated();
        $rows = collect($this->getJson('/api/staff')->json('data'))->keyBy('name');
        $this->assertSame(0, $rows['Zed']['weekly_load']);   // a teacher with no periods yet shows 0
        $this->assertNull($rows['Nora']['weekly_load']);      // a nurse has no teaching load
    }
}
