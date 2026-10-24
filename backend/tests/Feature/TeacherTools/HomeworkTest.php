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
use App\Modules\TeacherTools\Models\HomeworkFile;
use App\Modules\TeacherTools\Models\HomeworkSubmission;
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

    public function test_an_admin_does_not_send_homework(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/homework', $this->body())->assertForbidden();
        $this->getJson('/api/homework')->assertForbidden();
        $this->assertSame(0, HomeworkAssignment::count());
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

    private function studentUser(): User
    {
        $user = User::factory()->role(Role::Student)->create();
        $this->student->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_only_a_student_of_the_section_may_answer_a_published_homework(): void
    {
        $hw = $this->homework();
        $draft = $this->homework(['published_at' => null]);
        $elsewhere = $this->homework(['classroom_id' => $this->b->id]);
        Sanctum::actingAs($this->studentUser());
        $this->postJson("/api/homework/{$elsewhere->id}/submission", ['body' => 'x'])->assertForbidden();
        $this->postJson("/api/homework/{$draft->id}/submission", ['body' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->parent);
        $this->postJson("/api/homework/{$hw->id}/submission", ['body' => 'x'])->assertForbidden();
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/homework/{$hw->id}/submission", ['body' => 'x'])->assertForbidden();

    }

    private function upload(string $name = 'a.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    private function files(HomeworkAssignment $hw): array
    {
        return $hw->files()->pluck('id')->all();
    }

    public function test_a_teacher_adds_several_photos_and_a_new_one_never_replaces_the_old(): void
    {
        Storage::fake();
        $hw = $this->homework();
        Sanctum::actingAs($this->teacher);

        $this->post("/api/homework/{$hw->id}/images", ['images' => [$this->upload('1.jpg'), $this->upload('2.jpg')]], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'data.images');
        $this->post("/api/homework/{$hw->id}/images", ['images' => [$this->upload('3.png')]], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(3, 'data.images');

        $this->assertCount(3, HomeworkFile::all());
        foreach (HomeworkFile::all() as $file) {
            Storage::assertExists($file->path);
        }
        $this->assertSame([1, 2, 3], HomeworkFile::orderBy('id')->pluck('position')->all());
    }

    public function test_a_teacher_removes_one_photo_and_at_most_ten_are_kept(): void
    {
        Storage::fake();
        $hw = $this->homework();
        Sanctum::actingAs($this->teacher);
        $this->post("/api/homework/{$hw->id}/images", ['images' => [$this->upload(), $this->upload()]], ['Accept' => 'application/json'])->assertOk();
        [$one, $two] = $this->files($hw);
        $path = HomeworkFile::find($one)->path;

        $this->deleteJson("/api/homework/files/$one")->assertNoContent();
        Storage::assertMissing($path);
        $this->assertSame([$two], $this->files($hw));

        $this->post("/api/homework/{$hw->id}/images", ['images' => array_map(fn ($i) => $this->upload("p$i.jpg"), range(1, 10))], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->assertCount(1, HomeworkFile::all());
    }

    public function test_only_pictures_are_accepted_and_only_by_the_owner(): void
    {
        Storage::fake();
        $hw = $this->homework();
        Sanctum::actingAs($this->teacher);
        $this->post("/api/homework/{$hw->id}/images", ['images' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post("/api/homework/{$hw->id}/images", [], ['Accept' => 'application/json'])->assertUnprocessable();

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create());
        $this->post("/api/homework/{$hw->id}/images", ['images' => [$this->upload()]], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_homework_photo_is_served_to_the_section_its_parents_and_the_teacher_only(): void
    {
        Storage::fake();
        $hw = $this->homework();
        $file = HomeworkFile::create(['homework_assignment_id' => $hw->id, 'path' => $this->upload()->store('homework')]);

        foreach ([$this->teacher, $this->parent] as $user) {
            Sanctum::actingAs($user);
            $this->get("/api/homework/files/{$file->id}")->assertOk();
        }
        Sanctum::actingAs($this->studentUser());
        $this->get("/api/homework/files/{$file->id}")->assertOk();

        foreach ([User::factory()->role(Role::Guardian)->create(), User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create()] as $user) {
            Sanctum::actingAs($user);
            $this->get("/api/homework/files/{$file->id}")->assertForbidden();
            $this->deleteJson("/api/homework/files/{$file->id}")->assertForbidden();
        }
    }

    public function test_a_student_answers_with_several_pages_added_one_after_another(): void
    {
        Storage::fake();
        $hw = $this->homework(['due_at' => '2026-10-01 23:59:59']); // already past, so the answer is late
        Sanctum::actingAs($this->studentUser());

        $this->post("/api/homework/{$hw->id}/submission", ['body' => 'Page 1', 'images' => [$this->upload('1.jpg')]], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'data.images');
        $first = HomeworkFile::first();

        // Page 2 sent later keeps page 1.
        $this->post("/api/homework/{$hw->id}/submission", ['body' => 'Pages 1-2', 'images' => [$this->upload('2.jpg')]], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'data.images');
        $this->post("/api/homework/{$hw->id}/submission", ['body' => 'Pages 1-3', 'images' => [$this->upload('3.jpg'), $this->upload('4.jpg')]], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(4, 'data.images');
        Storage::assertExists($first->path);
        $this->assertSame(1, HomeworkSubmission::count());

        $this->getJson('/api/homework/me')->assertJsonPath('data.0.submission.body', 'Pages 1-3')->assertJsonPath('data.0.submission.late', true)->assertJsonCount(4, 'data.0.submission.images');
    }

    public function test_a_student_removes_chosen_pages_and_can_delete_the_answer(): void
    {
        Storage::fake();
        $hw = $this->homework();
        Sanctum::actingAs($this->studentUser());
        $this->post("/api/homework/{$hw->id}/submission", ['images' => [$this->upload(), $this->upload()]], ['Accept' => 'application/json'])->assertOk();
        [$one, $two] = HomeworkFile::orderBy('id')->pluck('id')->all();
        $path = HomeworkFile::find($one)->path;

        $this->post("/api/homework/{$hw->id}/submission", ['body' => 'Text', 'remove_file_ids' => [$one]], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'data.images');
        Storage::assertMissing($path);
        $this->assertSame([$two], HomeworkFile::pluck('id')->all());

        $this->deleteJson("/api/homework/files/$two")->assertNoContent(); // a student can also drop a page alone
        $this->deleteJson("/api/homework/{$hw->id}/submission")->assertNoContent();
        $this->assertSame(0, HomeworkSubmission::count());
        $this->assertSame(0, HomeworkFile::count());
    }

    public function test_an_empty_answer_too_many_pages_and_bad_files_are_refused(): void
    {
        Storage::fake();
        $hw = $this->homework();
        Sanctum::actingAs($this->studentUser());

        $this->postJson("/api/homework/{$hw->id}/submission", ['body' => ''])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->post("/api/homework/{$hw->id}/submission", ['images' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post("/api/homework/{$hw->id}/submission", ['images' => array_map(fn ($i) => $this->upload("p$i.jpg"), range(1, 10))], ['Accept' => 'application/json'])->assertOk();
        $this->post("/api/homework/{$hw->id}/submission", ['images' => [$this->upload()]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->assertCount(10, HomeworkFile::all());
    }

    public function test_the_teacher_sees_who_answered_and_who_did_not_and_opens_the_pages(): void
    {
        Storage::fake();
        $hw = $this->homework();
        $other = Student::create(['student_number' => 'S-2', 'name_ar' => 'ب', 'name_en' => 'B', 'gender' => 'male']);
        Enrollment::create(['student_id' => $other->id, 'classroom_id' => $this->a->id, 'academic_year_id' => $this->a->academic_year_id]);
        $sub = HomeworkSubmission::create(['homework_assignment_id' => $hw->id, 'student_id' => $this->student->id, 'body' => 'Done', 'submitted_at' => now()]);
        $file = HomeworkFile::create(['homework_submission_id' => $sub->id, 'path' => $this->upload()->store('homework-answers')]);

        Sanctum::actingAs($this->teacher);
        $rows = $this->getJson("/api/homework/{$hw->id}/submissions")->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame([true, false], collect($rows)->sortBy('student.id')->map(fn ($r) => $r['submission'] !== null)->values()->all());
        $this->assertSame([$file->id], collect($rows)->firstWhere('student.id', $this->student->id)['submission']['images']);
        $this->get("/api/homework/files/{$file->id}")->assertOk();

        Sanctum::actingAs($this->parent);
        $this->get("/api/homework/files/{$file->id}")->assertOk();
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create());
        $this->get("/api/homework/files/{$file->id}")->assertForbidden();
        $this->getJson("/api/homework/{$hw->id}/submissions")->assertForbidden();
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->get("/api/homework/files/{$file->id}")->assertForbidden();
    }

    public function test_deleting_the_homework_deletes_its_photos_the_answers_and_their_pages(): void
    {
        Storage::fake();
        $hw = $this->homework();
        $own = HomeworkFile::create(['homework_assignment_id' => $hw->id, 'path' => $this->upload()->store('homework')]);
        $sub = HomeworkSubmission::create(['homework_assignment_id' => $hw->id, 'student_id' => $this->student->id, 'submitted_at' => now()]);
        $page = HomeworkFile::create(['homework_submission_id' => $sub->id, 'path' => $this->upload()->store('homework-answers')]);

        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/api/homework/{$hw->id}")->assertNoContent();

        Storage::assertMissing($own->path);
        Storage::assertMissing($page->path);
        $this->assertSame(0, HomeworkSubmission::count());
        $this->assertSame(0, HomeworkFile::count());
    }
}
