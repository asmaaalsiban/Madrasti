<?php

namespace Tests\Feature\TeacherTools;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\TeacherTools\Models\DailySummary;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class DailySummaryTest extends AcademicTestCase
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
        return [...['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'date' => '2026-10-20', 'summary' => 'Fractions: adding with the same denominator'], ...$override];
    }

    private function summary(array $override = []): DailySummary
    {
        return DailySummary::create(['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-10-20', 'summary' => 'Old', 'published_at' => now(), ...$override]);
    }

    public function test_a_teacher_writes_the_summary_of_a_lesson_and_writing_again_replaces_it(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/daily-summaries', $this->body())->assertOk()->assertJsonPath('data.date', '2026-10-20')->assertJsonPath('data.teacher.id', $this->teacher->id);
        $this->putJson('/api/daily-summaries', $this->body(['summary' => 'Corrected']))->assertOk();

        $this->assertSame(1, DailySummary::count());
        $this->assertSame('Corrected', DailySummary::first()->summary);
        $this->assertNotNull(DailySummary::first()->published_at);
    }

    public function test_the_date_defaults_to_today_and_cannot_be_in_the_future(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/daily-summaries', $this->body(['date' => null]))->assertOk()->assertJsonPath('data.date', now()->toDateString());
        $this->putJson('/api/daily-summaries', $this->body(['date' => now()->addDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->putJson('/api/daily-summaries', $this->body(['summary' => '']))->assertUnprocessable()->assertJsonValidationErrors('summary');
    }

    public function test_a_teacher_only_writes_for_sections_and_subjects_they_teach(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->putJson('/api/daily-summaries', $this->body(['classroom_id' => $this->b->id]))->assertUnprocessable()->assertJsonValidationErrors('classroom_id');
        $this->assertSame(0, DailySummary::count());
    }

    public function test_a_teacher_lists_and_deletes_only_their_own(): void
    {
        $mine = $this->summary();
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $theirs = $this->summary(['teacher_id' => $other->id, 'date' => '2026-10-19', 'summary' => 'Theirs']);
        Sanctum::actingAs($this->teacher);

        $this->assertSame(['Old'], collect($this->getJson('/api/daily-summaries')->assertOk()->json('data'))->pluck('summary')->all());
        $this->deleteJson("/api/daily-summaries/{$theirs->id}")->assertForbidden();
        $this->deleteJson("/api/daily-summaries/{$mine->id}")->assertNoContent();
        $this->assertSame(1, DailySummary::count());
    }

    public function test_the_student_and_the_parent_read_the_published_summaries_of_their_section(): void
    {
        $this->summary(['summary' => 'For 5-A']);
        $this->summary(['classroom_id' => $this->b->id, 'summary' => 'For 5-B']);
        $this->summary(['date' => '2026-10-21', 'summary' => 'Draft', 'published_at' => null]);

        Sanctum::actingAs($this->parent);
        $this->assertSame(['For 5-A'], collect($this->getJson("/api/students/{$this->student->id}/daily-summaries")->assertOk()->json('data'))->pluck('summary')->all());

        $user = User::factory()->role(Role::Student)->create();
        $this->student->update(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $this->assertSame(['For 5-A'], collect($this->getJson('/api/daily-summaries/me')->assertOk()->json('data'))->pluck('summary')->all());
    }

    public function test_others_cannot_read_or_write(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson("/api/students/{$this->student->id}/daily-summaries")->assertForbidden();
        $this->putJson('/api/daily-summaries', $this->body())->assertForbidden();
        $this->actAsAdmin();
        $this->putJson('/api/daily-summaries', $this->body())->assertForbidden();
        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/students/{$this->student->id}/daily-summaries")->assertForbidden();
        $this->getJson('/api/daily-summaries/me')->assertForbidden();
    }
}
