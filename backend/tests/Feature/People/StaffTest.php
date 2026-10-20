<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Academic\AcademicTestCase;

class StaffTest extends AcademicTestCase
{
    private function body(array $override = []): array
    {
        return [...[
            'name' => 'Ahmad Ali', 'email' => 'ahmad@school.test', 'phone' => '0599000001', 'is_active' => true,
            'staff_type' => 'teacher',
        ], ...$override];
    }

    public function test_admin_creates_a_teacher_with_a_subject_and_the_default_password(): void
    {
        $this->actAsAdmin();
        $math = Subject::factory()->create();

        $id = $this->postJson('/api/staff', $this->body(['subject_id' => $math->id]))
            ->assertCreated()
            ->assertJsonPath('data.staff_type', 'teacher')
            ->assertJsonPath('data.category', 'teaching')
            ->assertJsonPath('data.subject.id', $math->id)
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password')
            ->json('data.id');

        $user = User::find($id);
        $this->assertSame(Role::Teacher, $user->role);
        $this->assertSame(StaffType::Teacher, $user->staffProfile->staff_type);
        $this->assertSame($math->id, $user->staffProfile->subject_id);
        $this->assertTrue(Hash::check(config('accounts.default_password'), $user->password));
    }

    public function test_management_types_get_an_admin_account_and_other_types_a_staff_account(): void
    {
        $this->actAsAdmin();

        $principal = $this->postJson('/api/staff', $this->body(['staff_type' => 'principal']))->assertCreated()->json('data.id');
        $nurse = $this->postJson('/api/staff', $this->body(['staff_type' => 'nurse', 'email' => 'n@school.test', 'phone' => '0599000002']))->assertCreated()->json('data.id');

        $this->assertSame(Role::Admin, User::find($principal)->role);
        $this->assertSame(Role::Teacher, User::find($nurse)->role);
    }

    public function test_subject_and_floor_are_kept_only_for_the_types_that_use_them(): void
    {
        $this->actAsAdmin();
        $subject = Subject::factory()->create();

        $nurse = $this->postJson('/api/staff', $this->body(['staff_type' => 'nurse', 'subject_id' => $subject->id, 'floor' => '2']))
            ->assertCreated()->assertJsonPath('data.subject', null)->assertJsonPath('data.floor', null)->json('data.id');
        $supervisor = $this->postJson('/api/staff', $this->body(['staff_type' => 'floor_supervisor', 'email' => 'f@school.test', 'phone' => '0599000003', 'floor' => '3', 'subject_id' => $subject->id]))
            ->assertCreated()->assertJsonPath('data.floor', '3')->assertJsonPath('data.subject', null)->json('data.id');

        $this->assertNull(User::find($nurse)->staffProfile->subject_id);
        $this->assertSame('3', User::find($supervisor)->staffProfile->floor);
    }

    public function test_the_staff_type_is_required_and_must_be_a_known_one(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/staff', $this->body(['staff_type' => null]))->assertUnprocessable()->assertJsonValidationErrors('staff_type');
        $this->postJson('/api/staff', $this->body(['staff_type' => 'wizard']))->assertUnprocessable()->assertJsonValidationErrors('staff_type');
        $this->postJson('/api/staff', $this->body(['subject_id' => 9999]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->postJson('/api/staff', $this->body(['email' => 'not-an-email', 'name' => '']))->assertUnprocessable()->assertJsonValidationErrors(['email', 'name']);
    }

    public function test_email_and_phone_must_be_unique_across_all_users(): void
    {
        $this->actAsAdmin();
        User::factory()->role(Role::Student)->create(['email' => 'taken@school.test', 'phone' => '0599111111']);

        $this->postJson('/api/staff', $this->body(['email' => 'taken@school.test']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/staff', $this->body(['phone' => '0599111111']))->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_the_list_only_has_staff_and_can_be_filtered_and_searched(): void
    {
        $this->actAsAdmin(); // the first admin is not staff
        $sara = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara', 'email' => 'sara@school.test']);
        $nurse = User::factory()->staff(StaffType::Nurse)->create(['name' => 'Nora']);
        User::factory()->staff(StaffType::HeadOfDepartment)->create(['name' => 'Hadi']);
        User::factory()->staff(StaffType::Accountant)->create(['name' => 'Ali']);
        User::factory()->role(Role::Student)->create(['name' => 'Student Sam']);

        $this->getJson('/api/staff')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/staff?category=teaching')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/staff?category=health')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nurse->id);
        $this->getJson('/api/staff?staff_type=accountant')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/staff?homeroom=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/staff?search=SARA')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sara->id);
        $this->getJson('/api/staff?category=nope')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_update_changes_the_type_the_subject_and_keeps_the_password(): void
    {
        $this->actAsAdmin();
        $subject = Subject::factory()->create();
        $staff = User::factory()->staff(StaffType::Teacher, ['subject_id' => $subject->id])->create(['password' => 'TheirOwnPass1', 'must_change_password' => false]);

        $this->putJson("/api/staff/{$staff->id}", $this->body(['email' => $staff->email, 'phone' => $staff->phone, 'staff_type' => 'nurse']))
            ->assertOk()->assertJsonPath('data.staff_type', 'nurse')->assertJsonPath('data.subject', null);

        $staff->refresh();
        $this->assertNull($staff->staffProfile->subject_id);
        $this->assertTrue(Hash::check('TheirOwnPass1', $staff->password));
        $this->assertFalse($staff->must_change_password);
    }

    public function test_promoting_to_management_or_demoting_changes_the_role_and_signs_the_user_out(): void
    {
        $this->actAsAdmin();
        $staff = User::factory()->staff(StaffType::Teacher)->create();
        $staff->createToken('web');
        $body = fn (string $type) => $this->body(['email' => $staff->email, 'phone' => $staff->phone, 'staff_type' => $type]);

        $this->putJson("/api/staff/{$staff->id}", $body('principal'))->assertOk();
        $this->assertSame(Role::Admin, $staff->fresh()->role);
        $this->assertSame(0, $staff->tokens()->count());

        $staff->createToken('web');
        $this->putJson("/api/staff/{$staff->id}", $body('accountant'))->assertOk();
        $this->assertSame(Role::Teacher, $staff->fresh()->role);
        $this->assertSame(0, $staff->tokens()->count());

        $staff->createToken('web');
        $this->putJson("/api/staff/{$staff->id}", $body('nurse'))->assertOk();
        $this->assertSame(1, $staff->tokens()->count(), 'same role: no sign-out');
    }

    public function test_only_staff_are_reachable_through_this_endpoint(): void
    {
        $this->actAsAdmin();
        $student = User::factory()->role(Role::Student)->create();
        $firstAdmin = User::factory()->role(Role::Admin)->create();

        $this->getJson("/api/staff/{$student->id}")->assertNotFound();
        $this->putJson("/api/staff/{$firstAdmin->id}", $this->body())->assertNotFound();
        $this->deleteJson("/api/staff/{$firstAdmin->id}")->assertNotFound();
    }

    public function test_deactivating_signs_out_and_deleting_frees_the_classrooms(): void
    {
        $this->actAsAdmin();
        $staff = User::factory()->staff(StaffType::Teacher)->create();
        $staff->createToken('web');
        $room = Classroom::factory()->create(['homeroom_teacher_id' => $staff->id]);

        $this->putJson("/api/staff/{$staff->id}", $this->body(['email' => $staff->email, 'phone' => $staff->phone, 'is_active' => false]))->assertOk();
        $this->assertSame(0, $staff->tokens()->count());

        $staff->createToken('web');
        $this->deleteJson("/api/staff/{$staff->id}")->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $staff->id]);
        $this->assertSame(0, $staff->tokens()->count());
        $this->assertNull($room->fresh()->homeroom_teacher_id);
    }

    public function test_staff_types_are_listed_for_the_forms(): void
    {
        $this->actAsAdmin();

        $response = $this->getJson('/api/staff-types')->assertOk();
        $types = collect($response->json('data'))->keyBy('value');

        $this->assertCount(count(StaffType::cases()), $types);
        $this->assertSame('teaching', $types['teacher']['category']);
        $this->assertTrue($types['teacher']['needs_subject']);
        $this->assertTrue($types['floor_supervisor']['needs_floor']);
        $this->assertTrue($types['principal']['grants_admin_access']);
        $this->assertTrue($types['head_of_department']['can_be_homeroom']);
        $this->assertFalse($types['nurse']['can_be_homeroom']);
    }

    public function test_admin_level_staff_can_be_reset_but_the_first_admin_and_yourself_cannot(): void
    {
        $me = $this->actAsAdmin();
        $principal = User::factory()->staff(StaffType::Principal)->create(['password' => 'TheirOwnPass1']);
        $firstAdmin = User::factory()->role(Role::Admin)->create();

        $this->postJson("/api/users/{$principal->id}/reset-password")->assertOk();
        $this->assertTrue(Hash::check(config('accounts.default_password'), $principal->fresh()->password));
        $this->postJson("/api/users/{$firstAdmin->id}/reset-password")->assertForbidden();
        $this->postJson("/api/users/{$me->id}/reset-password")->assertForbidden();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_is_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/staff')->assertForbidden();
        $this->postJson('/api/staff', $this->body())->assertForbidden();
        $this->getJson('/api/staff-types')->assertForbidden();
    }

    public function test_guests_are_unauthorized(): void
    {
        $this->getJson('/api/staff')->assertUnauthorized();
        $this->getJson('/api/staff-types')->assertUnauthorized();
    }

    public function test_an_admin_cannot_delete_deactivate_or_demote_themselves(): void
    {
        $principal = User::factory()->staff(StaffType::Principal)->create();
        Sanctum::actingAs($principal);

        $this->deleteJson("/api/staff/{$principal->id}")->assertForbidden();
        $this->putJson("/api/staff/{$principal->id}", $this->body(['email' => $principal->email, 'staff_type' => 'principal', 'is_active' => false]))->assertForbidden();
        $this->putJson("/api/staff/{$principal->id}", $this->body(['email' => $principal->email, 'staff_type' => 'nurse']))->assertForbidden();
        $this->assertNull($principal->fresh()->deleted_at);
    }

    public function test_a_teacher_who_can_no_longer_be_a_homeroom_teacher_is_removed_from_the_classroom(): void
    {
        $this->actAsAdmin();
        $teacher = User::factory()->staff(StaffType::Teacher)->create();
        $room = Classroom::factory()->create(['homeroom_teacher_id' => $teacher->id]);

        $this->putJson("/api/staff/{$teacher->id}", $this->body(['email' => $teacher->email, 'staff_type' => 'nurse']))->assertOk();

        $this->assertNull($room->fresh()->homeroom_teacher_id);
    }

    public function test_the_homeroom_list_has_only_active_staff(): void
    {
        $this->actAsAdmin();
        User::factory()->staff(StaffType::Teacher)->create(['name' => 'Active One']);
        User::factory()->staff(StaffType::Teacher)->inactive()->create(['name' => 'Gone One']);

        $this->getJson('/api/staff?homeroom=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Active One');
    }
}
