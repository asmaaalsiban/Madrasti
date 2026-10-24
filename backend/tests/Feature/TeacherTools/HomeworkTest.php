<?php

namespace Tests\Feature\TeacherTools;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\TeacherTools\Models\HomeworkAssignment;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class HomeworkTest extends AcademicTestCase
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
        return [...['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'title' => 'Page 10', 'description' => 'Solve 1-5', 'due_on' => '2026-11-01'], ...$override];
    }

    private function homework(array $override = []): HomeworkAssignment
    {
        return HomeworkAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'title' => 'Old', 'due_at' => '2026-11-01 23:59:59', 'published_at' => now(), ...$override]);
    }

    public function test_a_teacher_sends_homework_to_a_section_they_teach(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/homework', $this->body())->assertCreated()
            ->assertJsonPath('data.title', 'Page 10')->assertJsonPath('data.due_on', '2026-11-01')->assertJsonPath('data.teacher.id', $this->teacher->id);

        $this->assertDatabaseHas('homework_assignments', ['classroom_id' => $this->a->id, 'teacher_id' => $this->teacher->id]);
        $this->assertNotNull(HomeworkAssignment::first()->published_at);
    }

    public function test_a_teacher_cannot_send_homework_to_a_section_or_subject_they_do_not_teach(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/homework', $this->body(['classroom_id' => $this->b->id]))->assertUnprocessable();
        $this->postJson('/api/homework', $this->body(['title' => '', 'due_on' => 'x']))->assertUnprocessable()->assertJsonValidationErrors(['title', 'due_on']);
        $this->assertSame(0, HomeworkAssignment::count());
    }

    public function test_an_admin_may_send_to_any_assigned_section(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/homework', $this->body())->assertCreated();
    }

    public function test_a_teacher_lists_edits_and_deletes_only_their_own_homework(): void
    {
        $mine = $this->homework();
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $theirs = $this->homework(['teacher_id' => $other->id, 'title' => 'Theirs']);
        Sanctum::actingAs($this->teacher);

        $this->assertSame(['Old'], collect($this->getJson('/api/homework')->assertOk()->json('data'))->pluck('title')->all());
        $this->putJson("/api/homework/{$mine->id}", $this->body(['title' => 'New']))->assertOk()->assertJsonPath('data.title', 'New');
        $this->putJson("/api/homework/{$theirs->id}", $this->body())->assertForbidden();
        $this->deleteJson("/api/homework/{$theirs->id}")->assertForbidden();
        $this->deleteJson("/api/homework/{$mine->id}")->assertNoContent();
        $this->assertSame(1, HomeworkAssignment::count());
    }

    public function test_the_student_and_the_parent_see_the_homework_of_their_section_only(): void
    {
        $this->homework(['title' => 'For 5-A']);
        $this->homework(['classroom_id' => $this->b->id, 'title' => 'For 5-B']);
        $this->homework(['title' => 'Draft', 'published_at' => null]);

        Sanctum::actingAs($this->parent);
        $this->assertSame(['For 5-A'], collect($this->getJson("/api/students/{$this->student->id}/homework")->assertOk()->json('data'))->pluck('title')->all());

        $studentUser = User::factory()->role(Role::Student)->create();
        $this->student->update(['user_id' => $studentUser->id]);
        Sanctum::actingAs($studentUser);
        $this->assertSame(['For 5-A'], collect($this->getJson('/api/homework/me')->assertOk()->json('data'))->pluck('title')->all());
    }

    public function test_other_parents_and_teachers_cannot_read_a_students_homework(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson("/api/students/{$this->student->id}/homework")->assertForbidden();
        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/students/{$this->student->id}/homework")->assertForbidden();
        $this->getJson('/api/homework/me')->assertForbidden();
    }

    public function test_students_and_parents_cannot_create_homework(): void
    {
        Sanctum::actingAs($this->parent);
        $this->postJson('/api/homework', $this->body())->assertForbidden();
        $this->getJson('/api/homework')->assertForbidden();
    }

    public function test_a_teacher_adds_replaces_and_removes_a_picture_and_it_goes_with_the_homework(): void
    {
        Storage::fake();
        $hw = $this->homework();
        Sanctum::actingAs($this->teacher);

        $this->post("/api/homework/{$hw->id}/image", ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_image', true);
        $first = $hw->fresh()->attachment_path;
        Storage::assertExists($first);
        $this->post("/api/homework/{$hw->id}/image", ['image' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::assertMissing($first);

        $this->deleteJson("/api/homework/{$hw->id}/image")->assertNoContent();
        $this->assertNull($hw->fresh()->attachment_path);

        $this->post("/api/homework/{$hw->id}/image", ['image' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertOk();
        $path = $hw->fresh()->attachment_path;
        $this->deleteJson("/api/homework/{$hw->id}")->assertNoContent();
        Storage::assertMissing($path);
    }

    public function test_only_pictures_are_accepted_and_only_by_the_owner(): void
    {
        Storage::fake();
        $hw = $this->homework();
        Sanctum::actingAs($this->teacher);
        $this->post("/api/homework/{$hw->id}/image", ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post("/api/homework/{$hw->id}/image", [], ['Accept' => 'application/json'])->assertUnprocessable();

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create());
        $this->post("/api/homework/{$hw->id}/image", ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_picture_is_served_to_the_section_its_parents_and_the_teacher_only(): void
    {
        Storage::fake();
        $hw = $this->homework();
        $hw->update(['attachment_path' => UploadedFile::fake()->image('a.jpg')->store('homework')]);

        foreach ([$this->teacher, $this->parent] as $user) {
            Sanctum::actingAs($user);
            $this->get("/api/homework/{$hw->id}/image")->assertOk();
        }
        $studentUser = User::factory()->role(Role::Student)->create();
        $this->student->update(['user_id' => $studentUser->id]);
        Sanctum::actingAs($studentUser);
        $this->get("/api/homework/{$hw->id}/image")->assertOk();

        foreach ([User::factory()->role(Role::Guardian)->create(), User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create()] as $user) {
            Sanctum::actingAs($user);
            $this->get("/api/homework/{$hw->id}/image")->assertForbidden();
        }
        Sanctum::actingAs($this->teacher);
        $this->homework(['title' => 'No picture']);
        $this->get('/api/homework/'.HomeworkAssignment::where('title', 'No picture')->value('id').'/image')->assertNotFound();
    }
}
