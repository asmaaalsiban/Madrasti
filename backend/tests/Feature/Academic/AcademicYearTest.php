<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class AcademicYearTest extends AcademicTestCase
{
    private function body(array $override = []): array
    {
        return [...['name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => false], ...$override];
    }

    public function test_admin_can_crud_year(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/academic-years', $this->body())
            ->assertCreated()->assertJsonPath('data.starts_on', '2026-09-01')->json('data.id');
        $this->getJson('/api/academic-years')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->putJson("/api/academic-years/$id", $this->body(['name' => 'Y']))->assertOk()->assertJsonPath('data.name', 'Y');
        $this->deleteJson("/api/academic-years/$id")->assertNoContent();
    }

    public function test_ends_on_must_be_after_starts_on(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/academic-years', $this->body(['ends_on' => '2026-09-01']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson('/api/academic-years', $this->body(['ends_on' => '2026-01-01']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    }

    public function test_setting_current_unsets_previous_current(): void
    {
        $this->actAsAdmin();
        $a = $this->postJson('/api/academic-years', $this->body(['name' => 'A', 'is_current' => true]))->json('data.id');
        $b = $this->postJson('/api/academic-years', $this->body(['name' => 'B', 'is_current' => true]))->json('data.id');

        $this->assertFalse(AcademicYear::find($a)->is_current);
        $this->assertTrue(AcademicYear::find($b)->is_current);
        $this->assertSame(1, AcademicYear::where('is_current', true)->count());

        $this->putJson("/api/academic-years/$a", $this->body(['name' => 'A', 'is_current' => true]))->assertOk();
        $this->assertSame([$a], AcademicYear::where('is_current', true)->pluck('id')->all());
    }

    public function test_cannot_delete_year_with_classrooms(): void
    {
        $this->actAsAdmin();
        $year = AcademicYear::factory()->create();
        Classroom::factory()->create(['academic_year_id' => $year->id]);

        $this->deleteJson("/api/academic-years/{$year->id}")->assertUnprocessable();
        $this->assertDatabaseHas('academic_years', ['id' => $year->id]);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_is_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/academic-years')->assertForbidden();
        $this->postJson('/api/academic-years', $this->body())->assertForbidden();
    }
}
