<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class ClassroomTest extends AcademicTestCase
{
    private function body(array $override = []): array
    {
        return [...[
            'name' => '5-A',
            'grade_level_id' => GradeLevel::factory()->create()->id,
            'academic_year_id' => AcademicYear::factory()->create()->id,
            'homeroom_teacher_id' => null,
            'capacity' => 30,
        ], ...$override];
    }

    public function test_admin_can_crud_classroom(): void
    {
        $this->actAsAdmin();
        $teacher = User::factory()->role(Role::Teacher)->create();

        $id = $this->postJson('/api/classrooms', $this->body(['homeroom_teacher_id' => $teacher->id]))
            ->assertCreated()
            ->assertJsonPath('data.homeroom_teacher.id', $teacher->id)
            ->assertJsonStructure(['data' => ['id', 'name', 'capacity', 'grade_level' => ['id', 'name_ar', 'name_en'], 'academic_year' => ['id', 'name']]])
            ->json('data.id');
        $this->getJson("/api/classrooms/$id")->assertOk()->assertJsonPath('data.name', '5-A');
        $this->putJson("/api/classrooms/$id", $this->body(['name' => '5-B']))->assertOk()->assertJsonPath('data.name', '5-B')->assertJsonPath('data.homeroom_teacher', null);
        $this->deleteJson("/api/classrooms/$id")->assertNoContent();
    }

    public function test_homeroom_teacher_must_be_a_teacher(): void
    {
        $this->actAsAdmin();
        $parent = User::factory()->role(Role::Guardian)->create();

        $this->postJson('/api/classrooms', $this->body(['homeroom_teacher_id' => $parent->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('homeroom_teacher_id');
    }

    public function test_capacity_must_be_positive_and_foreign_keys_must_exist(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/classrooms', $this->body(['capacity' => 0]))->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->postJson('/api/classrooms', $this->body(['grade_level_id' => 9999]))->assertUnprocessable()->assertJsonValidationErrors('grade_level_id');
        $this->postJson('/api/classrooms', $this->body(['capacity' => null]))->assertCreated();
    }

    public function test_index_filters_by_grade_level_and_year(): void
    {
        $this->actAsAdmin();
        $target = Classroom::factory()->create();
        Classroom::factory()->create();

        $this->getJson('/api/classrooms?grade_level_id='.$target->grade_level_id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/classrooms?academic_year_id='.$target->academic_year_id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/classrooms?search='.$target->name)->assertOk()->assertJsonPath('data.0.id', $target->id);
    }

    public function test_cannot_delete_classroom_with_enrollments(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $studentId = DB::table('students')->insertGetId(['student_number' => 'S1', 'name_ar' => 'طالب', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insert(['student_id' => $studentId, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id, 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteJson("/api/classrooms/{$room->id}")->assertUnprocessable();
        $this->assertDatabaseHas('classrooms', ['id' => $room->id]);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_is_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/classrooms')->assertForbidden();
        $this->postJson('/api/classrooms', [])->assertForbidden();
    }
}
