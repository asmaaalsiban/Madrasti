<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Academic\AcademicTestCase;

class StudentTest extends AcademicTestCase
{
    private function body(array $override = []): array
    {
        return [...[
            'student_number' => 'S-1001', 'name_ar' => 'محمد', 'name_en' => 'Mohammad', 'birth_date' => '2015-04-01',
            'gender' => 'male', 'guardian_email' => 'dad@mail.test', 'guardian_name' => 'Abu Mohammad', 'guardian_phone' => '0599123456',
        ], ...$override];
    }

    public function test_admin_registers_a_student_and_the_student_account_is_generated(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();

        $response = $this->postJson('/api/students', $this->body(['classroom_id' => $room->id]))
            ->assertCreated()
            ->assertJsonPath('data.student_number', 'S-1001')
            ->assertJsonPath('data.email', 'mohammad.s1001@school.edu')
            ->assertJsonPath('data.classroom.id', $room->id)
            ->assertJsonPath('data.guardians.0.email', 'dad@mail.test')
            ->assertJsonPath('data.guardians.0.name', 'Abu Mohammad')
            ->assertJsonPath('data.account.email', 'mohammad.s1001@school.edu')
            ->assertJsonPath('data.account.password', config('accounts.default_password'));
        $id = $response->json('data.id');

        $student = Student::findOrFail($id);
        $account = $student->user;
        $this->assertSame('mohammad.s1001@school.edu', $account->email);
        $this->assertSame(Role::Student, $account->role);
        $this->assertTrue($account->must_change_password);
        $this->assertTrue(Hash::check(config('accounts.default_password'), $account->password));

        $parent = User::where('email', 'dad@mail.test')->firstOrFail();
        $this->assertSame(Role::Guardian, $parent->role);
        $this->assertSame('0599123456', $parent->phone);
        $this->assertTrue($parent->must_change_password);
        $this->assertTrue($student->guardians->contains($parent));
        $this->assertDatabaseHas('enrollments', ['student_id' => $id, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id]);
    }

    public function test_the_students_own_email_cannot_be_used_as_the_parent_email(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body(['guardian_email' => 'mohammad.s1001@school.edu']))
            ->assertUnprocessable()->assertJsonValidationErrors('guardian_email');

        $this->assertDatabaseMissing('users', ['email' => 'mohammad.s1001@school.edu']);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_the_parent_phone_is_returned_so_the_form_can_show_it(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/students', $this->body())->assertCreated()->assertJsonPath('data.guardians.0.phone', '0599123456');
    }

    public function test_the_generated_account_is_only_shown_when_the_student_is_created(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->body())->assertCreated()->json('data.id');

        $this->getJson("/api/students/$id")->assertOk()->assertJsonMissingPath('data.account')->assertJsonMissingPath('data.password');
        $this->getJson('/api/students')->assertOk()->assertJsonMissingPath('data.0.account');
        $this->putJson("/api/students/$id", $this->body())->assertOk()->assertJsonMissingPath('data.account');
    }

    public function test_the_generated_email_avoids_existing_accounts(): void
    {
        $this->actAsAdmin();
        User::factory()->staff(StaffType::Teacher)->create(['email' => 'mohammad.s1001@school.edu']);

        $this->postJson('/api/students', $this->body())->assertCreated()->assertJsonPath('data.email', 'mohammad.s1001-2@school.edu');
    }

    public function test_a_student_email_sent_by_the_client_is_ignored_and_the_email_never_changes_afterwards(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->body(['student_email' => 'chosen@mail.test']))->assertCreated()->assertJsonPath('data.email', 'mohammad.s1001@school.edu')->json('data.id');

        $this->putJson("/api/students/$id", $this->body(['student_number' => 'S-9999', 'name_en' => 'Moe', 'student_email' => 'other@mail.test']))
            ->assertOk()->assertJsonPath('data.student_number', 'S-9999')->assertJsonPath('data.email', 'mohammad.s1001@school.edu');
    }

    public function test_the_email_uses_only_the_code_when_there_is_no_english_name(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body(['name_en' => null]))->assertCreated()->assertJsonPath('data.email', 's1001@school.edu');
    }

    public function test_ids_sent_as_text_by_the_browser_are_accepted(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $other = Classroom::factory()->create(['academic_year_id' => $room->academic_year_id]);

        $id = $this->postJson('/api/students', $this->body(['classroom_id' => (string) $room->id]))
            ->assertCreated()->assertJsonPath('data.classroom.id', $room->id)->json('data.id');
        $this->putJson("/api/students/$id", $this->body(['classroom_id' => (string) $other->id]))
            ->assertOk()->assertJsonPath('data.classroom.id', $other->id);
        $this->postJson('/api/students', $this->body(['student_number' => 'S-2', 'guardian_email' => null, 'guardian_phone' => null, 'classroom_id' => '']))
            ->assertCreated()->assertJsonPath('data.classroom', null);
    }

    public function test_a_student_can_be_registered_without_a_classroom_or_a_parent(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body(['guardian_email' => null, 'guardian_name' => null, 'guardian_phone' => null]))
            ->assertCreated()->assertJsonPath('data.classroom', null)->assertJsonCount(0, 'data.guardians');
        $this->assertSame(0, DB::table('enrollments')->count());
        $this->assertSame(0, User::where('role', Role::Guardian)->count());
    }

    public function test_a_parent_with_two_children_gets_one_account_and_keeps_the_phone(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body())->assertCreated();
        $this->postJson('/api/students', $this->body(['student_number' => 'S-1002', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'guardian_name' => 'Other name', 'guardian_phone' => null]))->assertCreated();

        $parents = User::where('role', Role::Guardian)->get();
        $this->assertCount(1, $parents);
        $this->assertSame('Abu Mohammad', $parents[0]->name);
        $this->assertSame('0599123456', $parents[0]->phone);
        $this->assertSame(2, $parents[0]->children()->count());
    }

    public function test_the_parent_phone_is_updated_when_a_new_one_is_given(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/students', $this->body())->assertCreated();

        $this->postJson('/api/students', $this->body(['student_number' => 'S-1002', 'name_en' => 'Sara', 'guardian_phone' => '0599777777']))->assertCreated();

        $this->assertSame('0599777777', User::where('email', 'dad@mail.test')->value('phone'));
    }

    public function test_a_parent_phone_that_belongs_to_another_account_is_refused_and_nothing_is_created(): void
    {
        $this->actAsAdmin();
        User::factory()->staff(StaffType::Nurse)->create(['phone' => '0599123456']);
        $before = User::count();

        $this->postJson('/api/students', $this->body())->assertUnprocessable()->assertJsonValidationErrors('guardian_phone');

        $this->assertSame($before, User::count());
        $this->assertSame(0, Student::count());
    }

    public function test_the_parents_own_phone_is_not_a_conflict(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/students', $this->body())->assertCreated();

        $this->postJson('/api/students', $this->body(['student_number' => 'S-1002', 'name_en' => 'Sara']))->assertCreated();
    }

    public function test_the_parent_gets_a_default_name_when_none_is_given(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body(['guardian_name' => null]))->assertCreated();

        $this->assertSame(__('people.guardian_of', ['name' => 'محمد']), User::where('email', 'dad@mail.test')->value('name'));
    }

    public function test_a_parent_email_that_belongs_to_another_kind_of_account_is_refused_and_nothing_is_created(): void
    {
        $this->actAsAdmin();
        User::factory()->staff(StaffType::Teacher)->create(['email' => 'other0@mail.test']);
        User::factory()->role(Role::Student)->create(['email' => 'other1@mail.test']);
        User::factory()->role(Role::Admin)->create(['email' => 'other2@mail.test']);
        $before = User::count();

        foreach (range(0, 2) as $i) {
            $this->postJson('/api/students', $this->body(['guardian_email' => "other$i@mail.test"]))
                ->assertUnprocessable()->assertJsonValidationErrors('guardian_email');
        }

        $this->assertSame($before, User::count());
        $this->assertSame(0, Student::count());
    }

    public function test_numbers_are_unique_and_input_is_validated(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/students', $this->body())->assertCreated();

        $this->postJson('/api/students', $this->body(['guardian_email' => 'dad2@mail.test', 'guardian_phone' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('student_number');
        $this->postJson('/api/students', [])->assertUnprocessable()->assertJsonValidationErrors(['student_number', 'name_ar']);
        $this->postJson('/api/students', $this->body(['student_number' => 'S-3', 'gender' => 'other', 'classroom_id' => 9999, 'birth_date' => 'tomorrow']))
            ->assertUnprocessable()->assertJsonValidationErrors(['gender', 'classroom_id', 'birth_date']);
    }

    public function test_update_changes_the_student_the_classroom_and_replaces_the_primary_parent(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $sameYearRoom = Classroom::factory()->create(['academic_year_id' => $room->academic_year_id]);
        $nextYear = AcademicYear::factory()->create(['name' => '2027/2028']);
        $nextYearRoom = Classroom::factory()->create(['academic_year_id' => $nextYear->id]);
        $id = $this->postJson('/api/students', $this->body(['classroom_id' => $room->id, 'guardian_email' => null, 'guardian_phone' => null]))->json('data.id');

        $this->putJson("/api/students/$id", $this->body(['name_ar' => 'محمد علي', 'classroom_id' => $sameYearRoom->id, 'guardian_email' => 'mum@mail.test', 'guardian_name' => 'Mum', 'guardian_phone' => '0599555555']))
            ->assertOk()
            ->assertJsonPath('data.name_ar', 'محمد علي')
            ->assertJsonPath('data.classroom.id', $sameYearRoom->id)
            ->assertJsonPath('data.guardians.0.email', 'mum@mail.test');
        $this->assertSame(1, DB::table('enrollments')->where('student_id', $id)->count());
        $this->assertSame('محمد علي', Student::find($id)->user->name);

        $this->putJson("/api/students/$id", $this->body(['classroom_id' => $nextYearRoom->id, 'guardian_email' => null, 'guardian_phone' => null]))
            ->assertOk()->assertJsonPath('data.classroom.id', $nextYearRoom->id);
        $this->assertSame(2, DB::table('enrollments')->where('student_id', $id)->count());
    }

    public function test_update_keeps_the_password_of_the_student(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->body(['guardian_email' => null, 'guardian_phone' => null]))->json('data.id');
        $user = Student::find($id)->user;
        $user->forceFill(['password' => 'TheirOwnPass1', 'must_change_password' => false])->save();

        $this->putJson("/api/students/$id", $this->body(['guardian_email' => null, 'guardian_phone' => null, 'name_en' => 'Mo']))->assertOk();

        $this->assertTrue(Hash::check('TheirOwnPass1', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_update_refuses_a_parent_email_of_another_account_type(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->body(['guardian_email' => null, 'guardian_phone' => null]))->json('data.id');
        User::factory()->staff(StaffType::Teacher)->create(['email' => 'teacher@mail.test']);

        $this->putJson("/api/students/$id", $this->body(['guardian_email' => 'teacher@mail.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('guardian_email');
    }

    public function test_list_search_and_filter(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $this->postJson('/api/students', $this->body(['classroom_id' => $room->id]))->assertCreated();
        $this->postJson('/api/students', $this->body(['student_number' => 'S-2000', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'guardian_email' => null, 'guardian_phone' => null]))->assertCreated();

        $this->getJson('/api/students')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/students?search=S-2000')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/students?search=sara.s2000')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_number', 'S-2000');
        $this->getJson('/api/students?search=سارة')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/students?classroom_id='.$room->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_number', 'S-1001');
        $this->getJson('/api/students?search=nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deleting_a_student_signs_them_out_and_keeps_the_parent(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->body())->json('data.id');
        $student = Student::find($id);
        $student->user->createToken('web');

        $this->deleteJson("/api/students/$id")->assertNoContent();

        $this->assertSoftDeleted('students', ['id' => $id]);
        $this->assertSoftDeleted('users', ['id' => $student->user_id]);
        $this->assertSame(0, $student->user()->withTrashed()->first()->tokens()->count());
        $this->assertDatabaseHas('users', ['email' => 'dad@mail.test', 'deleted_at' => null]);
    }

    public function test_registering_a_student_is_audited_without_any_password(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body())->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Student::class, 'action' => 'created']);
        $this->assertStringNotContainsString('$2y$', DB::table('audit_logs')->pluck('new_values')->implode(''));
    }

    public function test_a_parent_sees_the_login_email_of_their_own_children_only(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create(['name' => '1-A']);
        $this->postJson('/api/students', $this->body(['classroom_id' => $room->id]))->assertCreated();
        $this->postJson('/api/students', $this->body(['student_number' => 'S-1002', 'name_en' => 'Sara', 'name_ar' => 'سارة']))->assertCreated();
        $this->postJson('/api/students', $this->body(['student_number' => 'S-3000', 'name_en' => 'Omar', 'guardian_email' => 'other-family@mail.test', 'guardian_phone' => null]))->assertCreated();
        $token = User::where('email', 'dad@mail.test')->first()->createToken('web')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $children = $this->withToken($token)->getJson('/api/auth/me')->assertOk()->json('data.children');

        $this->assertCount(2, $children);
        $this->assertEqualsCanonicalizing(['mohammad.s1001@school.edu', 'sara.s1002@school.edu'], array_column($children, 'email'));
        $first = collect($children)->firstWhere('student_number', 'S-1001');
        $this->assertSame('1-A', $first['classroom']);
        $this->assertStringNotContainsString('S-3000', json_encode($children));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_is_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/students')->assertForbidden();
        $this->postJson('/api/students', $this->body())->assertForbidden();
    }

    public function test_guests_are_unauthorized(): void
    {
        $this->getJson('/api/students')->assertUnauthorized();
    }

    public function test_admin_searches_existing_parents_by_name_phone_or_email_with_their_children(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/students', $this->body())->assertCreated();
        $this->postJson('/api/students', $this->body(['student_number' => 'S-1002', 'name_en' => 'Layla']))->assertCreated();
        User::factory()->role(Role::Guardian)->create(['name' => 'Someone Else', 'phone' => '0599777777']);
        User::factory()->staff()->create(['name' => 'Abu Teacher']);

        $this->getJson('/api/guardians?search=Abu')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'dad@mail.test')
            ->assertJsonPath('data.0.phone', '0599123456')
            ->assertJsonCount(2, 'data.0.children');
        $this->getJson('/api/guardians?search=0599123')->assertJsonCount(1, 'data');
        $this->getJson('/api/guardians?search=dad@')->assertJsonCount(1, 'data');
        $this->getJson('/api/guardians')->assertJsonCount(2, 'data'); // never staff or students
    }

    public function test_entering_the_email_of_an_existing_parent_links_the_second_child_to_the_same_account(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/students', $this->body())->assertCreated();
        $this->postJson('/api/students', $this->body(['student_number' => 'S-1002', 'name_en' => 'Layla', 'guardian_name' => null, 'guardian_phone' => null]))
            ->assertCreated()->assertJsonPath('data.guardians.0.name', 'Abu Mohammad');

        $this->assertSame(1, User::where('email', 'dad@mail.test')->count());
        $this->assertSame(2, User::where('email', 'dad@mail.test')->first()->children()->count());
    }

    public function test_only_admins_search_parents(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());
        $this->getJson('/api/guardians')->assertForbidden();
    }
}
