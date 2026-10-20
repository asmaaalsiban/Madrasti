<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
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
            'gender' => 'male', 'student_email' => 'mohammad@school.test',
            'guardian_email' => 'dad@mail.test', 'guardian_name' => 'Abu Mohammad',
        ], ...$override];
    }

    public function test_admin_registers_a_student_with_accounts_for_the_student_and_the_parent(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();

        $id = $this->postJson('/api/students', $this->body(['classroom_id' => $room->id]))
            ->assertCreated()
            ->assertJsonPath('data.student_number', 'S-1001')
            ->assertJsonPath('data.email', 'mohammad@school.test')
            ->assertJsonPath('data.classroom.id', $room->id)
            ->assertJsonPath('data.guardians.0.email', 'dad@mail.test')
            ->assertJsonPath('data.guardians.0.name', 'Abu Mohammad')
            ->assertJsonMissingPath('data.password')
            ->json('data.id');

        $student = Student::findOrFail($id);
        $account = $student->user;
        $this->assertSame(Role::Student, $account->role);
        $this->assertTrue($account->is_active);
        $this->assertTrue($account->must_change_password);
        $this->assertTrue(Hash::check(config('accounts.default_password'), $account->password));

        $parent = User::where('email', 'dad@mail.test')->firstOrFail();
        $this->assertSame(Role::Guardian, $parent->role);
        $this->assertTrue($parent->must_change_password);
        $this->assertTrue(Hash::check(config('accounts.default_password'), $parent->password));
        $this->assertTrue($student->guardians->contains($parent));

        $this->assertDatabaseHas('enrollments', ['student_id' => $id, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id]);
    }

    public function test_a_student_can_be_registered_without_a_classroom_or_a_parent(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body(['guardian_email' => null, 'guardian_name' => null]))
            ->assertCreated()->assertJsonPath('data.classroom', null)->assertJsonCount(0, 'data.guardians');
        $this->assertSame(0, DB::table('enrollments')->count());
        $this->assertSame(0, User::where('role', Role::Guardian)->count());
    }

    public function test_a_parent_with_two_children_gets_one_account(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body())->assertCreated();
        $this->postJson('/api/students', $this->body(['student_number' => 'S-1002', 'student_email' => 'sara@school.test', 'name_ar' => 'سارة', 'guardian_name' => 'Other name']))->assertCreated();

        $parents = User::where('role', Role::Guardian)->get();
        $this->assertCount(1, $parents);
        $this->assertSame('Abu Mohammad', $parents[0]->name);
        $this->assertSame(2, $parents[0]->children()->count());
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
        foreach ([Role::Teacher, Role::Student, Role::Admin] as $i => $role) {
            User::factory()->role($role)->create(['email' => "other$i@mail.test"]);
        }
        $before = User::count();

        foreach (range(0, 2) as $i) {
            $this->postJson('/api/students', $this->body(['guardian_email' => "other$i@mail.test"]))
                ->assertUnprocessable()->assertJsonValidationErrors('guardian_email');
        }

        $this->assertSame($before, User::count());
        $this->assertSame(0, Student::count());
    }

    public function test_student_and_parent_cannot_share_an_email(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body(['guardian_email' => 'mohammad@school.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('guardian_email');
    }

    public function test_emails_and_numbers_are_unique_and_input_is_validated(): void
    {
        $this->actAsAdmin();
        User::factory()->role(Role::Teacher)->create(['email' => 'taken@school.test']);
        $this->postJson('/api/students', $this->body())->assertCreated();

        $this->postJson('/api/students', $this->body(['student_number' => 'S-2', 'student_email' => 'TAKEN@school.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('student_email');
        $this->postJson('/api/students', $this->body(['student_email' => 'new@school.test', 'guardian_email' => 'dad2@mail.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('student_number');
        $this->postJson('/api/students', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['student_number', 'name_ar', 'student_email']);
        $this->postJson('/api/students', $this->body(['student_number' => 'S-3', 'student_email' => 'x@school.test', 'gender' => 'other', 'classroom_id' => 9999, 'birth_date' => 'tomorrow']))
            ->assertUnprocessable()->assertJsonValidationErrors(['gender', 'classroom_id', 'birth_date']);
    }

    public function test_student_emails_are_stored_in_lower_case(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/students', $this->body(['student_email' => ' Mohammad@School.TEST ']))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'mohammad@school.test', 'role' => 'student']);
    }

    public function test_update_changes_the_student_the_email_the_classroom_and_can_add_a_parent(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $sameYearRoom = Classroom::factory()->create(['academic_year_id' => $room->academic_year_id]);
        $nextYear = AcademicYear::factory()->create(['name' => '2027/2028']);
        $nextYearRoom = Classroom::factory()->create(['academic_year_id' => $nextYear->id]);
        $id = $this->postJson('/api/students', $this->body(['classroom_id' => $room->id, 'guardian_email' => null]))->json('data.id');

        $this->putJson("/api/students/$id", $this->body(['name_ar' => 'محمد علي', 'student_email' => 'new@school.test', 'classroom_id' => $sameYearRoom->id, 'guardian_email' => 'mum@mail.test', 'guardian_name' => 'Mum']))
            ->assertOk()
            ->assertJsonPath('data.name_ar', 'محمد علي')
            ->assertJsonPath('data.email', 'new@school.test')
            ->assertJsonPath('data.classroom.id', $sameYearRoom->id)
            ->assertJsonPath('data.guardians.0.email', 'mum@mail.test');
        $this->assertSame(1, DB::table('enrollments')->where('student_id', $id)->count());
        $this->assertSame('new@school.test', Student::find($id)->user->email);

        $this->putJson("/api/students/$id", $this->body(['student_email' => 'new@school.test', 'classroom_id' => $nextYearRoom->id, 'guardian_email' => null]))
            ->assertOk()->assertJsonPath('data.classroom.id', $nextYearRoom->id);
        $this->assertSame(2, DB::table('enrollments')->where('student_id', $id)->count());
    }

    public function test_update_keeps_the_password_and_the_current_email_is_not_a_conflict(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->body(['guardian_email' => null]))->json('data.id');
        $user = Student::find($id)->user;
        $user->forceFill(['password' => 'TheirOwnPass1', 'must_change_password' => false])->save();

        $this->putJson("/api/students/$id", $this->body(['guardian_email' => null, 'name_en' => 'Mo']))->assertOk();

        $this->assertTrue(Hash::check('TheirOwnPass1', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_update_refuses_a_parent_email_of_another_account_type(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->body(['guardian_email' => null]))->json('data.id');
        User::factory()->role(Role::Teacher)->create(['email' => 'teacher@mail.test']);

        $this->putJson("/api/students/$id", $this->body(['guardian_email' => 'teacher@mail.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('guardian_email');
    }

    public function test_list_search_and_filter(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $this->postJson('/api/students', $this->body(['classroom_id' => $room->id]))->assertCreated();
        $this->postJson('/api/students', $this->body(['student_number' => 'S-2000', 'student_email' => 'sara@school.test', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'guardian_email' => null]))->assertCreated();

        $this->getJson('/api/students')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/students?search=S-2000')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/students?search=sara@school')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_number', 'S-2000');
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
}
