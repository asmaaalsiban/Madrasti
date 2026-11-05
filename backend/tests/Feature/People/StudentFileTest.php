<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Attachments\Attachment;
use App\Shared\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class StudentFileTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    private function student(): Student
    {
        return Student::create(['student_number' => 'F-1', 'name_ar' => 'طالب', 'name_en' => 'Student', 'gender' => 'male']);
    }

    private function pdf(string $name = 'birth.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    public function test_the_principal_adds_files_to_a_student_and_the_list_shows_them(): void
    {
        $this->actAsAdmin();
        $student = $this->student();

        $id = $this->postJson("/api/attachments/student/{$student->id}", ['files' => [$this->pdf(), $this->pdf('records.pdf')]])->assertCreated()->assertJsonCount(2, 'data')->json('data.0.id');
        Storage::assertExists(Attachment::findOrFail($id)->path);

        $this->getJson('/api/students')->assertOk()->assertJsonPath('data.0.files.0.name', 'birth.pdf')->assertJsonCount(2, 'data.0.files');
        $this->get("/api/attachments/$id")->assertOk();

        $this->deleteJson("/api/attachments/$id")->assertNoContent();
        $this->getJson('/api/students')->assertJsonCount(1, 'data.0.files');
    }

    public function test_a_student_file_is_for_the_principal_only(): void
    {
        $this->actAsAdmin();
        $student = $this->student();
        $id = $this->postJson("/api/attachments/student/{$student->id}", ['files' => [$this->pdf()]])->assertCreated()->json('data.0.id');

        foreach ([Role::Teacher, Role::Student, Role::Guardian] as $role) {
            Sanctum::actingAs(User::factory()->role($role)->create());
            $this->postJson("/api/attachments/student/{$student->id}", ['files' => [$this->pdf('x.pdf')]])->assertForbidden();
            $this->get("/api/attachments/$id")->assertNotFound();
            $this->deleteJson("/api/attachments/$id")->assertForbidden();
        }
    }

    public function test_deleting_the_student_removes_the_files(): void
    {
        $this->actAsAdmin();
        $student = $this->student();
        $id = $this->postJson("/api/attachments/student/{$student->id}", ['files' => [$this->pdf()]])->assertCreated()->json('data.0.id');
        $path = Attachment::findOrFail($id)->path;

        $this->deleteJson("/api/students/{$student->id}")->assertNoContent();

        $this->assertNull(Attachment::find($id));
        Storage::assertMissing($path);
    }

    public function test_a_bad_file_type_is_refused(): void
    {
        $this->actAsAdmin();
        $student = $this->student();

        $this->postJson("/api/attachments/student/{$student->id}", ['files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertUnprocessable();
    }
}
