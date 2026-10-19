<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminOverviewTest extends AcademicTestCase
{
    public function test_overview_returns_counts(): void
    {
        $this->actAsAdmin();
        $this->getJson('/api/admin/overview')->assertOk()->assertExactJson(['data' => ['academic_years' => 0, 'grade_levels' => 0, 'classrooms' => 0, 'subjects' => 0]]);

        AcademicYear::factory()->count(2)->create();
        GradeLevel::factory()->count(3)->create();
        Classroom::factory()->create();
        Subject::factory()->count(4)->create();

        // Classroom factory also creates one year and one level.
        $this->getJson('/api/admin/overview')->assertOk()->assertExactJson(['data' => ['academic_years' => 3, 'grade_levels' => 4, 'classrooms' => 1, 'subjects' => 4]]);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_overview_forbidden_for_non_admin(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/admin/overview')->assertForbidden();
    }
}
