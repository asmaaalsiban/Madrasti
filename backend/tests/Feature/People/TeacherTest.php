<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Academic\AcademicTestCase;

class TeacherTest extends AcademicTestCase
{
    private function body(array $override = []): array
    {
        return [...['name' => 'Ahmad Ali', 'email' => 'ahmad@school.test', 'phone' => '0599000001', 'is_active' => true], ...$override];
    }

    public function test_admin_creates_a_teacher_with_the_default_password_that_must_be_changed(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/teachers', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.email', 'ahmad@school.test')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password')
            ->json('data.id');

        $teacher = User::find($id);
        $this->assertSame(Role::Teacher, $teacher->role);
        $this->assertTrue(Hash::check(config('accounts.default_password'), $teacher->password));
        $this->assertTrue($teacher->must_change_password);
    }

    public function test_admin_can_list_show_update_and_search_teachers(): void
    {
        $this->actAsAdmin();
        $a = User::factory()->role(Role::Teacher)->create(['name' => 'Sara', 'email' => 'sara@school.test']);
        User::factory()->role(Role::Teacher)->create(['name' => 'Omar', 'email' => 'omar@school.test']);
        User::factory()->role(Role::Student)->create(['name' => 'Student Sam']);

        $this->getJson('/api/teachers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/teachers?search=SARA')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson("/api/teachers/{$a->id}")->assertOk()->assertJsonPath('data.name', 'Sara');

        $this->putJson("/api/teachers/{$a->id}", $this->body(['email' => 'sara@school.test', 'name' => 'Sara K', 'is_active' => false]))
            ->assertOk()->assertJsonPath('data.name', 'Sara K')->assertJsonPath('data.is_active', false);
    }

    public function test_updating_a_teacher_does_not_touch_the_password(): void
    {
        $this->actAsAdmin();
        $teacher = User::factory()->role(Role::Teacher)->create(['password' => 'TheirOwnPass1', 'must_change_password' => false]);

        $this->putJson("/api/teachers/{$teacher->id}", $this->body(['email' => $teacher->email]))->assertOk();

        $this->assertTrue(Hash::check('TheirOwnPass1', $teacher->fresh()->password));
        $this->assertFalse($teacher->fresh()->must_change_password);
    }

    public function test_only_teachers_are_reachable_through_this_endpoint(): void
    {
        $this->actAsAdmin();
        $student = User::factory()->role(Role::Student)->create();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->getJson("/api/teachers/{$student->id}")->assertNotFound();
        $this->putJson("/api/teachers/{$admin->id}", $this->body())->assertNotFound();
        $this->deleteJson("/api/teachers/{$admin->id}")->assertNotFound();
    }

    public function test_email_and_phone_must_be_unique_across_all_users(): void
    {
        $this->actAsAdmin();
        User::factory()->role(Role::Student)->create(['email' => 'taken@school.test', 'phone' => '0599111111']);

        $this->postJson('/api/teachers', $this->body(['email' => 'taken@school.test']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/teachers', $this->body(['phone' => '0599111111']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/teachers', $this->body(['email' => 'not-an-email', 'name' => '']))->assertUnprocessable()->assertJsonValidationErrors(['email', 'name']);
    }

    public function test_deleting_a_teacher_signs_them_out_and_frees_their_classrooms(): void
    {
        $this->actAsAdmin();
        $teacher = User::factory()->role(Role::Teacher)->create();
        $teacher->createToken('web');
        $room = Classroom::factory()->create(['homeroom_teacher_id' => $teacher->id]);

        $this->deleteJson("/api/teachers/{$teacher->id}")->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $teacher->id]);
        $this->assertSame(0, $teacher->tokens()->count());
        $this->assertNull($room->fresh()->homeroom_teacher_id);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_is_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/teachers')->assertForbidden();
        $this->postJson('/api/teachers', $this->body())->assertForbidden();
    }

    public function test_guests_are_unauthorized(): void
    {
        $this->getJson('/api/teachers')->assertUnauthorized();
    }
}
