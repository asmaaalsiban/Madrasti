<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\Term;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class TermTest extends AcademicTestCase
{
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
    }

    private function body(array $override = []): array
    {
        return [...[
            'academic_year_id' => $this->year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31',
        ], ...$override];
    }

    public function test_admin_can_crud_terms(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/terms', $this->body())->assertCreated()
            ->assertJsonPath('data.name', 'First term')
            ->assertJsonPath('data.academic_year.id', $this->year->id)
            ->json('data.id');
        $this->getJson("/api/terms/$id")->assertOk()->assertJsonPath('data.starts_on', '2026-09-01');
        $this->putJson("/api/terms/$id", $this->body(['name' => 'Autumn']))->assertOk()->assertJsonPath('data.name', 'Autumn');
        $this->deleteJson("/api/terms/$id")->assertNoContent();
    }

    public function test_the_list_can_be_filtered_by_academic_year(): void
    {
        $this->actAsAdmin();
        $other = AcademicYear::factory()->create();
        Term::create($this->body());
        Term::create($this->body(['academic_year_id' => $other->id, 'name' => 'Other', 'starts_on' => '2025-09-01', 'ends_on' => '2026-01-31']));

        $this->getJson('/api/terms')->assertJsonCount(2, 'data');
        $this->getJson("/api/terms?academic_year_id={$this->year->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'First term');
    }

    public function test_a_term_must_end_after_it_starts_and_stay_inside_its_academic_year(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/terms', $this->body(['ends_on' => '2026-09-01']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson('/api/terms', $this->body(['starts_on' => '2026-08-01']))->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson('/api/terms', $this->body(['ends_on' => '2027-07-30']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson('/api/terms', $this->body(['academic_year_id' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');
    }

    public function test_terms_of_the_same_year_cannot_overlap_but_can_touch_other_years(): void
    {
        $this->actAsAdmin();
        $first = Term::create($this->body());

        $this->postJson('/api/terms', $this->body(['name' => 'Second', 'starts_on' => '2027-01-15', 'ends_on' => '2027-06-30']))
            ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson('/api/terms', $this->body(['name' => 'Second', 'starts_on' => '2027-02-01', 'ends_on' => '2027-06-30']))->assertCreated();
        // Editing a term does not collide with itself.
        $this->putJson("/api/terms/{$first->id}", $this->body(['ends_on' => '2027-01-20']))->assertOk();
    }

    public function test_a_term_with_assessments_cannot_be_deleted(): void
    {
        $this->actAsAdmin();
        $term = Term::create($this->body());
        DB::table('assessments')->insert($this->assessmentRow($term));

        $this->deleteJson("/api/terms/{$term->id}")->assertUnprocessable();
    }

    private function assessmentRow(Term $term): array
    {
        $room = Classroom::factory()->create();
        $teacher = User::factory()->staff()->create();

        return [
            'classroom_id' => $room->id, 'subject_id' => Subject::factory()->create()->id, 'term_id' => $term->id,
            'teacher_id' => $teacher->id, 'title' => 'Quiz', 'type' => 'quiz', 'max_score' => 10, 'weight' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/terms')->assertForbidden();
        $this->postJson('/api/terms', $this->body())->assertForbidden();
    }
}
