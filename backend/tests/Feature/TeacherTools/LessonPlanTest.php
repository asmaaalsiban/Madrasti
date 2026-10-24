<?php

namespace Tests\Feature\TeacherTools;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\TeacherTools\Models\LessonPlan;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class LessonPlanTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $a;

    private Classroom $b;

    private Subject $math;

    private Student $student;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $this->a = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $this->b = Classroom::factory()->create(['name' => '5-B', 'academic_year_id' => $year->id]);
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        $this->student = Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'name_en' => 'Student', 'gender' => 'male']);
        Enrollment::create(['student_id' => $this->student->id, 'classroom_id' => $this->a->id, 'academic_year_id' => $year->id]);
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->student->guardians()->attach($this->parent->id);
    }

    private function body(array $override = []): array
    {
        return [...['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'date' => '2026-10-21', 'title' => 'Fractions', 'objectives' => 'Add fractions', 'content' => 'Monday: intro...', 'shared_with_parents' => true], ...$override];
    }

    private function plan(array $override = []): LessonPlan
    {
        return LessonPlan::create(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'week_starts_on' => '2026-10-18', 'title' => 'Old', 'status' => 'draft', 'shared_with_parents' => true, ...$override]);
    }

    public function test_a_teacher_writes_the_plan_of_a_week_as_a_draft_and_the_week_starts_on_sunday(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/lesson-plans', $this->body())->assertOk()
            ->assertJsonPath('data.week_starts_on', '2026-10-18')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.title', 'Fractions');
        $this->putJson('/api/lesson-plans', $this->body(['date' => '2026-10-24', 'title' => 'Fractions v2']))->assertOk();

        $this->assertSame(1, LessonPlan::count());
        $this->assertSame('Fractions v2', LessonPlan::first()->title);
    }

    public function test_a_teacher_only_plans_sections_they_teach_and_needs_a_title(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/lesson-plans', $this->body(['classroom_id' => $this->b->id]))->assertUnprocessable()->assertJsonValidationErrors('classroom_id');
        $this->putJson('/api/lesson-plans', $this->body(['title' => '']))->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame(0, LessonPlan::count());
    }

    public function test_the_teacher_submits_the_admin_approves_and_an_approved_plan_is_locked(): void
    {
        $plan = $this->plan();
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/lesson-plans/{$plan->id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->putJson('/api/lesson-plans', $this->body())->assertUnprocessable(); // cannot be changed while waiting

        $admin = $this->actAsAdmin();
        $this->postJson("/api/lesson-plans/{$plan->id}/review", ['decision' => 'approve', 'note' => 'Good'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame($admin->id, $plan->fresh()->reviewed_by);

        Sanctum::actingAs($this->teacher);
        $this->putJson('/api/lesson-plans', $this->body())->assertUnprocessable();
        $this->deleteJson("/api/lesson-plans/{$plan->id}")->assertUnprocessable();
    }

    public function test_a_rejected_plan_comes_back_with_the_note_and_can_be_fixed_and_resubmitted(): void
    {
        $plan = $this->plan(['status' => 'submitted']);
        $this->actAsAdmin();
        $this->postJson("/api/lesson-plans/{$plan->id}/review", ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/lesson-plans/{$plan->id}/review", ['decision' => 'reject', 'note' => 'Add objectives'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.review_note', 'Add objectives');

        Sanctum::actingAs($this->teacher);
        $this->putJson('/api/lesson-plans', $this->body(['objectives' => 'Now with objectives']))->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/lesson-plans/{$plan->id}/submit")->assertOk();
    }

    public function test_only_a_submitted_plan_is_reviewed_and_only_a_draft_or_rejected_one_is_submitted(): void
    {
        $draft = $this->plan();
        $this->actAsAdmin();
        $this->postJson("/api/lesson-plans/{$draft->id}/review", ['decision' => 'approve'])->assertUnprocessable();

        $approved = $this->plan(['week_starts_on' => '2026-10-11', 'status' => 'approved']);
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/lesson-plans/{$approved->id}/submit")->assertUnprocessable();
    }

    public function test_a_teacher_lists_only_their_plans_and_the_admin_lists_what_waits_for_review(): void
    {
        $this->plan(['status' => 'submitted']);
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $this->plan(['teacher_id' => $other->id, 'week_starts_on' => '2026-10-11', 'title' => 'Theirs']);
        $this->plan(['week_starts_on' => '2026-10-04', 'title' => 'Draft']);

        Sanctum::actingAs($this->teacher);
        $this->assertCount(2, $this->getJson('/api/lesson-plans')->assertOk()->json('data'));

        $this->actAsAdmin();
        $this->assertSame(['Old'], collect($this->getJson('/api/lesson-plans/review')->assertOk()->json('data'))->pluck('title')->all());
    }

    public function test_a_teacher_cannot_touch_another_teachers_plan(): void
    {
        $plan = $this->plan();
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create());

        $this->postJson("/api/lesson-plans/{$plan->id}/submit")->assertForbidden();
        $this->deleteJson("/api/lesson-plans/{$plan->id}")->assertForbidden();
        $this->postJson("/api/lesson-plans/{$plan->id}/review", ['decision' => 'approve'])->assertForbidden();
        $this->getJson('/api/lesson-plans/review')->assertForbidden();
    }

    public function test_a_draft_is_deleted_by_its_teacher(): void
    {
        $plan = $this->plan();
        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/api/lesson-plans/{$plan->id}")->assertNoContent();
        $this->assertSame(0, LessonPlan::count());
    }

    public function test_students_and_parents_read_only_approved_plans_shared_with_them(): void
    {
        $this->plan(['status' => 'approved', 'title' => 'Shown', 'week_starts_on' => '2026-10-18']);
        $this->plan(['status' => 'approved', 'title' => 'Not shared', 'shared_with_parents' => false, 'week_starts_on' => '2026-10-11']);
        $this->plan(['status' => 'submitted', 'title' => 'Waiting', 'week_starts_on' => '2026-10-04']);
        $this->plan(['classroom_id' => $this->b->id, 'status' => 'approved', 'title' => 'Other section', 'week_starts_on' => '2026-09-27']);

        Sanctum::actingAs($this->parent);
        $this->assertSame(['Shown'], collect($this->getJson("/api/students/{$this->student->id}/lesson-plans")->assertOk()->json('data'))->pluck('title')->all());

        $user = User::factory()->role(Role::Student)->create();
        $this->student->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $this->assertSame(['Shown'], collect($this->getJson('/api/lesson-plans/me')->assertOk()->json('data'))->pluck('title')->all());
    }

    public function test_other_families_and_students_cannot_read_or_write(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson("/api/students/{$this->student->id}/lesson-plans")->assertForbidden();
        $this->putJson('/api/lesson-plans', $this->body())->assertForbidden();
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/lesson-plans/me')->assertForbidden();
    }
}
