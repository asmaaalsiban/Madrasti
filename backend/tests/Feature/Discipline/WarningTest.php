<?php

namespace Tests\Feature\Discipline;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Discipline\Models\Warning;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class WarningTest extends AcademicTestCase
{
    private Student $student;

    private User $parent;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'name_en' => 'Sami', 'gender' => 'male']);
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->student->guardians()->attach($this->parent->id);
        $this->teacher = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
    }

    private function body(array $extra = []): array
    {
        return ['subject_type' => 'student', 'student_id' => $this->student->id, 'category' => 'violence', 'level' => 3, 'title' => 'Hit a teacher', 'body' => 'Details...', ...$extra];
    }

    private function notices(User $u, string $kind): int
    {
        return DB::table('notifications')->where('notifiable_id', $u->id)->where('data', 'like', "%$kind%")->count();
    }

    public function test_the_admin_writes_a_warning_for_a_student_and_the_parent_is_told_and_sees_it_on_the_childs_page(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/warnings', $this->body())->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.level', 3)->assertJsonPath('data.student.name_en', 'Sami');
        $this->assertSame(1, $this->notices($this->parent, 'warning_student_parent'));

        Sanctum::actingAs($this->parent);
        $this->getJson("/api/students/{$this->student->id}/warnings")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Hit a teacher');
    }

    public function test_the_student_sees_their_own_and_another_family_sees_nothing(): void
    {
        $studentUser = User::factory()->role(Role::Student)->create();
        $this->student->update(['user_id' => $studentUser->id]);
        $this->actAsAdmin();
        $this->postJson('/api/warnings', $this->body())->assertCreated();
        $this->assertSame(1, $this->notices($studentUser, 'warning_student_self'));

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/warnings/mine')->assertOk()->assertJsonCount(1, 'data');

        $other = User::factory()->role(Role::Guardian)->create();
        Sanctum::actingAs($other);
        $this->getJson("/api/students/{$this->student->id}/warnings")->assertForbidden();
    }

    public function test_a_parent_can_say_they_have_seen_it_and_nobody_else_can(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/warnings', $this->body())->json('data.id');

        $other = User::factory()->role(Role::Guardian)->create();
        Sanctum::actingAs($other);
        $this->postJson("/api/warnings/$id/acknowledge")->assertForbidden();

        Sanctum::actingAs($this->parent);
        $this->postJson("/api/warnings/$id/acknowledge")->assertOk();
        $this->assertNotNull(Warning::find($id)->acknowledged_at);
        $this->assertSame($this->parent->id, Warning::find($id)->acknowledged_by);
    }

    public function test_a_teacher_proposes_a_warning_it_waits_for_the_admin_and_nobody_is_told_until_it_is_approved(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->postJson('/api/warnings', $this->body(['level' => 1, 'category' => 'behavior']))->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame(0, $this->notices($this->parent, 'warning_student_parent'));
        $this->getJson("/api/students/{$this->student->id}/warnings")->assertForbidden(); // a teacher does not read the parents' page
        $this->getJson('/api/warnings')->assertOk()->assertJsonCount(1, 'data'); // but sees what they wrote

        $this->actAsAdmin();
        $this->postJson("/api/warnings/$id/approve")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(1, $this->notices($this->parent, 'warning_student_parent'));
        $this->postJson("/api/warnings/$id/approve")->assertStatus(422);

        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/warnings/$id/approve")->assertForbidden();
    }

    public function test_a_withdrawn_warning_leaves_the_parents_page_but_stays_in_the_admins_record(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/warnings', $this->body())->json('data.id');
        $this->postJson("/api/warnings/$id/withdraw")->assertStatus(422); // a reason is needed
        $this->postJson("/api/warnings/$id/withdraw", ['reason' => 'Wrong student'])->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->getJson("/api/students/{$this->student->id}/warnings")->assertJsonCount(1, 'data')->assertJsonPath('data.0.withdrawn_reason', 'Wrong student');
        Sanctum::actingAs($this->parent);
        $this->getJson("/api/students/{$this->student->id}/warnings")->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/warnings/$id/acknowledge")->assertForbidden();
    }

    public function test_a_warning_for_a_member_of_staff_is_the_admins_alone_and_the_person_sees_it(): void
    {
        $body = ['subject_type' => 'staff', 'user_id' => $this->teacher->id, 'category' => 'late', 'level' => 1, 'title' => 'Late three times'];
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/warnings', $body)->assertForbidden(); // a teacher cannot warn a colleague

        $this->actAsAdmin();
        $id = $this->postJson('/api/warnings', $body)->assertCreated()->json('data.id');
        $this->assertSame(1, $this->notices($this->teacher, 'warning_staff'));
        $this->postJson('/api/warnings', [...$body, 'user_id' => User::factory()->role(Role::Guardian)->create()->id])->assertStatus(422); // not staff

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/warnings/mine')->assertOk()->assertJsonPath('data.0.title', 'Late three times');
        $this->postJson("/api/warnings/$id/acknowledge")->assertOk();
    }

    public function test_the_fields_are_validated(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/warnings', $this->body(['level' => 9]))->assertStatus(422)->assertJsonValidationErrors('level');
        $this->postJson('/api/warnings', $this->body(['category' => 'nope']))->assertStatus(422);
        $this->postJson('/api/warnings', $this->body(['student_id' => null]))->assertStatus(422);
        $this->postJson('/api/warnings', $this->body(['issued_on' => now()->addDay()->toDateString()]))->assertStatus(422);
    }
}
