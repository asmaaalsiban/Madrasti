<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Tests\TenantTestCase;

class AuthTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([InitializeTenancyByRequestData::class]);
    }

    public function test_user_can_login_with_email(): void
    {
        $user = User::factory()->role(Role::Admin)->create(['email' => 'a@school.test']);

        $this->postJson('/api/auth/login', ['login' => 'a@school.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonStructure(['token']);
    }

    public function test_user_can_login_with_phone(): void
    {
        User::factory()->create(['phone' => '0599999999']);

        $this->postJson('/api/auth/login', ['login' => '0599999999', 'password' => 'password'])
            ->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create();

        $this->postJson('/api/auth/login', ['login' => $user->email, 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_and_logout_work_with_token(): void
    {
        $user = User::factory()->role(Role::Student)->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'student');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_student_sees_their_section_and_a_teacher_their_subject_in_me(): void
    {
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $user = User::factory()->role(Role::Student)->create();
        $student = Student::create(['student_number' => 'S-1', 'name_ar' => 'أحمد خالد', 'name_en' => 'Ahmad Khaled', 'gender' => 'male', 'user_id' => $user->id]);
        Enrollment::create(['student_id' => $student->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);

        $this->actingAs($user)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.classroom', '5-A');

        $math = Subject::factory()->create(['name_ar' => 'رياضيات', 'name_en' => 'Math']);
        $head = User::factory()->staff(StaffType::HeadOfDepartment, ['subject_id' => $math->id])->create();
        $this->actingAs($head)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.staff_type', 'head_of_department')->assertJsonPath('data.subject.name_en', 'Math');
    }
}
