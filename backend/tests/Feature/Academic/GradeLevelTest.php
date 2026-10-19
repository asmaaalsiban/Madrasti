<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class GradeLevelTest extends AcademicTestCase
{
    public function test_admin_can_crud_grade_level(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/grade-levels', ['name_ar' => 'الصف الأول', 'name_en' => 'Grade 1', 'sort_order' => 1])
            ->assertCreated()->assertJsonPath('data.sort_order', 1)->json('data.id');
        $this->getJson("/api/grade-levels/$id")->assertOk()->assertJsonPath('data.name_en', 'Grade 1');
        $this->putJson("/api/grade-levels/$id", ['name_ar' => 'الأول', 'name_en' => 'Grade One', 'sort_order' => 2])
            ->assertOk()->assertJsonPath('data.name_en', 'Grade One');
        $this->deleteJson("/api/grade-levels/$id")->assertNoContent();
    }

    public function test_index_is_ordered_by_sort_order(): void
    {
        $this->actAsAdmin();
        GradeLevel::factory()->create(['sort_order' => 3, 'name_en' => 'Three']);
        GradeLevel::factory()->create(['sort_order' => 1, 'name_en' => 'One']);

        $this->getJson('/api/grade-levels')->assertOk()->assertJsonPath('data.0.name_en', 'One')->assertJsonPath('data.1.name_en', 'Three');
    }

    public function test_cannot_delete_grade_level_with_classrooms(): void
    {
        $this->actAsAdmin();
        $level = GradeLevel::factory()->create();
        Classroom::factory()->create(['grade_level_id' => $level->id]);

        $this->deleteJson("/api/grade-levels/{$level->id}", [], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('academic.in_use', ['resource' => __('academic.resources.grade_level')], 'en'));
        $this->assertDatabaseHas('grade_levels', ['id' => $level->id]);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_is_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/grade-levels')->assertForbidden();
        $this->postJson('/api/grade-levels', ['name_ar' => 'x', 'name_en' => 'x'])->assertForbidden();
    }
}
