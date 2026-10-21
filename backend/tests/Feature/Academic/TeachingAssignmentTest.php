<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class TeachingAssignmentTest extends AcademicTestCase
{
    private Classroom $room;

    private Subject $math;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->room = Classroom::factory()->create(['name' => '5-A']);
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create(['name' => 'Sara']);
    }

    private function body(array $override = []): array
    {
        return [...[
            'classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4,
        ], ...$override];
    }

    public function test_admin_assigns_a_teacher_to_a_subject_in_a_classroom(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/teaching-assignments', $this->body())->assertCreated()
            ->assertJsonPath('data.classroom.name', '5-A')
            ->assertJsonPath('data.subject.name_en', 'Math')
            ->assertJsonPath('data.teacher.name', 'Sara')
            ->assertJsonPath('data.weekly_periods', 4)
            ->json('data.id');
        $this->getJson("/api/teaching-assignments/$id")->assertOk();
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create(['name' => 'Omar']);
        $this->putJson("/api/teaching-assignments/$id", $this->body(['teacher_id' => $other->id, 'weekly_periods' => 5]))
            ->assertOk()->assertJsonPath('data.teacher.name', 'Omar');
        $this->deleteJson("/api/teaching-assignments/$id")->assertNoContent();
    }

    public function test_a_subject_has_one_teacher_per_classroom(): void
    {
        $this->actAsAdmin();
        TeachingAssignment::create($this->body());
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();

        $this->postJson('/api/teaching-assignments', $this->body(['teacher_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }

    public function test_the_teacher_must_be_an_active_teaching_staff_member_of_that_subject(): void
    {
        $this->actAsAdmin();
        $science = Subject::factory()->create();
        $wrongSubject = User::factory()->staff(StaffType::Teacher, ['subject_id' => $science->id])->create();
        $nurse = User::factory()->staff(StaffType::Nurse)->create();
        $inactive = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->inactive()->create();
        $parent = User::factory()->role(Role::Guardian)->create();

        foreach ([$wrongSubject, $nurse, $inactive, $parent] as $bad) {
            $this->postJson('/api/teaching-assignments', $this->body(['teacher_id' => $bad->id]))
                ->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        }
        $this->postJson('/api/teaching-assignments', $this->body(['classroom_id' => 'abc']))->assertUnprocessable();
        $this->postJson('/api/teaching-assignments', $this->body(['weekly_periods' => 0]))->assertUnprocessable()->assertJsonValidationErrors('weekly_periods');
    }

    public function test_the_list_filters_by_classroom_subject_and_teacher(): void
    {
        $this->actAsAdmin();
        $otherRoom = Classroom::factory()->create();
        TeachingAssignment::create($this->body());
        TeachingAssignment::create($this->body(['classroom_id' => $otherRoom->id]));

        $this->getJson('/api/teaching-assignments')->assertJsonCount(2, 'data');
        $this->getJson("/api/teaching-assignments?classroom_id={$this->room->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/teaching-assignments?teacher_id={$this->teacher->id}")->assertJsonCount(2, 'data');
        $this->getJson('/api/teaching-assignments?subject_id='.Subject::factory()->create()->id)->assertJsonCount(0, 'data');
    }

    public function test_the_staff_list_can_be_limited_to_one_subject(): void
    {
        $this->actAsAdmin();
        User::factory()->staff(StaffType::Teacher, ['subject_id' => Subject::factory()->create()->id])->create();

        $this->getJson("/api/staff?subject_id={$this->math->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sara');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/teaching-assignments')->assertForbidden();
        $this->postJson('/api/teaching-assignments', $this->body())->assertForbidden();
    }
}
