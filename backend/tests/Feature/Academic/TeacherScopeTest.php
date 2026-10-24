<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Services\AutoTeachingAssigner;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Collection;

class TeacherScopeTest extends AcademicTestCase
{
    private AcademicYear $year;

    private Subject $math;

    private GradeLevel $g2;

    private GradeLevel $g8;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->math = Subject::factory()->create(['code' => 'MTH', 'name_en' => 'Math']);
        $this->g2 = GradeLevel::factory()->create(['sort_order' => 5]);
        $this->g8 = GradeLevel::factory()->create(['sort_order' => 11]);
        foreach ([$this->g2, $this->g8] as $g) {
            GradeLevelSubject::create(['grade_level_id' => $g->id, 'subject_id' => $this->math->id, 'weekly_periods' => 5]);
        }
    }

    private function room(GradeLevel $grade, ?string $gender, string $name): Classroom
    {
        return Classroom::factory()->create(['name' => $name, 'grade_level_id' => $grade->id, 'academic_year_id' => $this->year->id, 'gender' => $gender]);
    }

    private function teacher(array $profile = []): User
    {
        return User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id, ...$profile])->create();
    }

    private function auto(): Collection
    {
        return collect(app(AutoTeachingAssigner::class)->handle(24, false)['unassigned']);
    }

    public function test_the_staff_form_saves_the_grades_and_the_students_a_teacher_teaches(): void
    {
        $this->actAsAdmin();
        $body = ['name' => 'Sara', 'email' => 's@x.test', 'staff_type' => 'teacher', 'subject_id' => $this->math->id, 'grade_from_id' => $this->g2->id, 'grade_to_id' => $this->g8->id, 'teaches_gender' => 'female'];

        $id = $this->postJson('/api/staff', $body)->assertCreated()
            ->assertJsonPath('data.grade_from.id', $this->g2->id)->assertJsonPath('data.grade_to.id', $this->g8->id)->assertJsonPath('data.teaches_gender', 'female')->json('data.id');
        $this->putJson("/api/staff/$id", [...$body, 'grade_from_id' => null, 'grade_to_id' => null, 'teaches_gender' => null])->assertOk()
            ->assertJsonPath('data.grade_from', null)->assertJsonPath('data.teaches_gender', null);
    }

    public function test_the_grade_range_is_validated_and_only_kept_for_teaching_staff(): void
    {
        $this->actAsAdmin();
        $body = ['name' => 'Sara', 'email' => 's@x.test', 'staff_type' => 'teacher', 'subject_id' => $this->math->id];

        $this->postJson('/api/staff', [...$body, 'grade_from_id' => $this->g2->id])->assertUnprocessable()->assertJsonValidationErrors('grade_to_id');
        $this->postJson('/api/staff', [...$body, 'grade_from_id' => $this->g8->id, 'grade_to_id' => $this->g2->id])->assertUnprocessable()->assertJsonValidationErrors('grade_to_id');
        $this->postJson('/api/staff', [...$body, 'teaches_gender' => 'robots'])->assertUnprocessable()->assertJsonValidationErrors('teaches_gender');
        $this->postJson('/api/staff', ['name' => 'Nora', 'email' => 'n@x.test', 'staff_type' => 'nurse', 'teaches_gender' => 'female'])->assertCreated()->assertJsonPath('data.teaches_gender', null);
    }

    public function test_the_distribution_keeps_a_teacher_inside_their_grades(): void
    {
        $lower = $this->room($this->g2, null, '2-A');
        $upper = $this->room($this->g8, null, '8-A');
        $basic = $this->teacher(['grade_from_id' => $this->g2->id, 'grade_to_id' => $this->g2->id]);
        $prep = $this->teacher(['grade_from_id' => $this->g8->id, 'grade_to_id' => $this->g8->id]);

        $this->assertCount(0, $this->auto());

        $this->assertSame($basic->id, TeachingAssignment::where('classroom_id', $lower->id)->value('teacher_id'));
        $this->assertSame($prep->id, TeachingAssignment::where('classroom_id', $upper->id)->value('teacher_id'));
    }

    public function test_boys_only_and_girls_only_teachers_only_get_their_own_sections_and_mixed_sections_need_a_teacher_for_both(): void
    {
        $boys = $this->room($this->g2, 'male', '2-M');
        $girls = $this->room($this->g2, 'female', '2-F');
        $mixed = $this->room($this->g2, 'mixed', '2-X');
        $male = $this->teacher(['teaches_gender' => 'male']);
        $female = $this->teacher(['teaches_gender' => 'female']);
        $both = $this->teacher();

        $this->assertCount(0, $this->auto());

        $of = fn (Classroom $c) => TeachingAssignment::where('classroom_id', $c->id)->value('teacher_id');
        $this->assertSame($male->id, $of($boys));
        $this->assertSame($female->id, $of($girls));
        $this->assertSame($both->id, $of($mixed));
    }

    public function test_a_section_nobody_may_teach_is_reported(): void
    {
        $this->room($this->g8, 'male', '8-M');
        $this->teacher(['teaches_gender' => 'female']);
        $this->teacher(['grade_from_id' => $this->g2->id, 'grade_to_id' => $this->g2->id]);

        $unassigned = $this->auto();

        $this->assertSame(['8-M'], $unassigned->pluck('classroom')->all());
    }

    public function test_assigning_by_hand_refuses_a_teacher_outside_their_scope(): void
    {
        $girls = $this->room($this->g2, 'female', '2-F');
        $upper = $this->room($this->g8, null, '8-A');
        $maleTeacher = $this->teacher(['teaches_gender' => 'male']);
        $lowerTeacher = $this->teacher(['grade_from_id' => $this->g2->id, 'grade_to_id' => $this->g2->id]);
        $this->actAsAdmin();
        $body = fn (Classroom $room, User $teacher) => ['classroom_id' => $room->id, 'subject_id' => $this->math->id, 'teacher_id' => $teacher->id, 'weekly_periods' => 5];

        $this->postJson('/api/teaching-assignments', $body($girls, $maleTeacher))->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->postJson('/api/teaching-assignments', $body($upper, $lowerTeacher))->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->postJson('/api/teaching-assignments', $body($girls, $lowerTeacher))->assertCreated();
    }

    public function test_an_old_assignment_outside_the_teachers_scope_is_found_replaced_and_never_reaches_the_timetable(): void
    {
        $lower = $this->room($this->g2, null, '2-A');
        $upper = $this->room($this->g8, null, '8-A');
        $basic = $this->teacher(['grade_from_id' => $this->g2->id, 'grade_to_id' => $this->g2->id]);
        $other = $this->teacher(['grade_from_id' => $this->g8->id, 'grade_to_id' => $this->g8->id]);
        // Made before the scope existed: the basic teacher teaches the upper grade too.
        TeachingAssignment::create(['classroom_id' => $upper->id, 'subject_id' => $this->math->id, 'teacher_id' => $basic->id, 'weekly_periods' => 5]);
        $this->actAsAdmin();
        Period::create(['day_of_week' => 0, 'number' => 1, 'starts_at' => '08:00', 'ends_at' => '08:45']);

        $generated = $this->postJson('/api/timetable/generate', ['seed' => 1, 'dry_run' => true])->assertOk();
        $this->assertSame(['8-A'], collect($generated->json('data.out_of_scope'))->pluck('classroom')->all());
        $this->assertSame(0, TimetableEntry::where('teacher_id', $basic->id)->count());

        $preview = $this->postJson('/api/teaching-assignments/auto', ['dry_run' => true])->assertOk();
        $preview->assertJsonPath('data.invalid', 1);
        $this->assertSame($basic->id, TeachingAssignment::where('classroom_id', $upper->id)->value('teacher_id'), 'a preview changes nothing');

        $this->postJson('/api/teaching-assignments/auto')->assertOk()->assertJsonPath('data.invalid', 1);
        $this->assertSame($other->id, TeachingAssignment::where('classroom_id', $upper->id)->value('teacher_id'));
        $this->assertSame($basic->id, TeachingAssignment::where('classroom_id', $lower->id)->value('teacher_id'));
        $this->postJson('/api/teaching-assignments/auto')->assertOk()->assertJsonPath('data.invalid', 0);
    }

    public function test_the_staff_form_saves_the_sections_a_teacher_teaches(): void
    {
        $a = $this->room($this->g2, null, '2-A');
        $b = $this->room($this->g2, null, '2-B');
        $this->actAsAdmin();
        $body = ['name' => 'Head', 'email' => 'h@x.test', 'staff_type' => 'head_of_department', 'subject_id' => $this->math->id, 'classroom_ids' => [$a->id, $b->id]];

        $id = $this->postJson('/api/staff', $body)->assertCreated()->assertJsonPath('data.classroom_ids', [$a->id, $b->id])->assertJsonCount(2, 'data.classrooms')->json('data.id');
        $this->putJson("/api/staff/$id", [...$body, 'classroom_ids' => [$b->id]])->assertOk()->assertJsonPath('data.classroom_ids', [$b->id]);
        $this->putJson("/api/staff/$id", [...$body, 'classroom_ids' => []])->assertOk()->assertJsonPath('data.classroom_ids', []);
        $this->postJson('/api/staff', [...$body, 'email' => 'z@x.test', 'classroom_ids' => [999]])->assertUnprocessable()->assertJsonValidationErrors('classroom_ids.0');
        $this->postJson('/api/staff', ['name' => 'Nora', 'email' => 'n@x.test', 'staff_type' => 'nurse', 'classroom_ids' => [$a->id]])->assertCreated()->assertJsonPath('data.classroom_ids', []);
    }

    public function test_a_teacher_with_chosen_sections_gets_only_those_and_is_preferred_for_them(): void
    {
        $a = $this->room($this->g2, null, '2-A');
        $b = $this->room($this->g2, null, '2-B');
        $c = $this->room($this->g2, null, '2-C');
        $general = $this->teacher();
        $head = $this->teacher();
        $head->staffProfile->classrooms()->sync([$b->id]);
        $of = fn (Classroom $room) => TeachingAssignment::where('classroom_id', $room->id)->value('teacher_id');

        $this->assertCount(0, $this->auto());

        $this->assertSame($head->id, $of($b), 'his chosen section goes to him');
        $this->assertSame($general->id, $of($a));
        $this->assertSame($general->id, $of($c));
    }

    public function test_a_teacher_with_chosen_sections_never_teaches_another_section(): void
    {
        $a = $this->room($this->g2, null, '2-A');
        $b = $this->room($this->g2, null, '2-B');
        $head = $this->teacher();
        $head->staffProfile->classrooms()->sync([$a->id]);

        $unassigned = $this->auto();

        $this->assertSame(['2-B'], $unassigned->pluck('classroom')->all());
        TeachingAssignment::query()->delete();
        $this->actAsAdmin();
        $this->postJson('/api/teaching-assignments', ['classroom_id' => $b->id, 'subject_id' => $this->math->id, 'teacher_id' => $head->id, 'weekly_periods' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->postJson('/api/teaching-assignments', ['classroom_id' => $a->id, 'subject_id' => $this->math->id, 'teacher_id' => $head->id, 'weekly_periods' => 5])->assertCreated();
    }
}
