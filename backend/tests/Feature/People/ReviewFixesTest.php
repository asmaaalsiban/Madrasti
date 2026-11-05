<?php

namespace Tests\Feature\People;

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Academic\AcademicTestCase;

class ReviewFixesTest extends AcademicTestCase
{
    private function studentBody(array $override = []): array
    {
        return [...[
            'student_number' => 'S-1001', 'name_ar' => 'محمد', 'student_email' => 'mohammad@school.test',
            'guardian_email' => 'dad@mail.test', 'guardian_name' => 'Dad', 'guardian_phone' => '0599111222',
        ], ...$override];
    }

    public function test_login_is_limited_per_ip_so_one_machine_cannot_spray_the_default_password(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->postJson('/api/auth/login', ['login' => "user$i@school.test", 'password' => '123456789'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['login' => 'user31@school.test', 'password' => '123456789'])->assertStatus(429);
    }

    public function test_every_signed_in_school_route_checks_the_account_is_active_and_the_password_was_changed(): void
    {
        $open = ['api/school', 'api/school/logo', 'api/auth/login', 'api/auth/forgot-password', 'api/auth/reset-password', 'api/auth/logout', 'api/auth/me', 'api/auth/change-password', 'api/payments/webhook/{provider}']; // the webhook is called by the payment provider and trusted only by its signature
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/central/') || in_array($route->uri(), $open, true)) {
                continue;
            }
            $middleware = app('router')->gatherRouteMiddleware($route);
            $this->assertContains(EnsureAccountActive::class, $middleware, $route->uri());
            $this->assertContains(EnsurePasswordChanged::class, $middleware, $route->uri());
            $checked++;
        }

        $this->assertGreaterThan(10, $checked);
    }

    public function test_a_deactivated_account_is_refused_and_signed_out(): void
    {
        $admin = User::factory()->role(Role::Admin)->create(['is_active' => false]);
        $token = $admin->createToken('web')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/overview')
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_changing_the_parent_email_replaces_the_primary_parent_instead_of_adding_one(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->studentBody())->json('data.id');

        $response = $this->putJson("/api/students/$id", $this->studentBody(['guardian_email' => 'mum@mail.test', 'guardian_name' => 'Mum', 'guardian_phone' => '0599111333']))
            ->assertOk()->assertJsonCount(1, 'data.guardians')->assertJsonPath('data.guardians.0.email', 'mum@mail.test');

        $student = Student::find($id);
        $this->assertSame(['mum@mail.test'], $student->guardians->pluck('email')->all());
        $this->assertTrue((bool) $student->guardians->first()->pivot->is_primary);
        $this->assertDatabaseHas('users', ['email' => 'dad@mail.test', 'deleted_at' => null]);
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_saving_a_student_again_keeps_the_primary_parent_flag(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->studentBody())->json('data.id');

        $this->putJson("/api/students/$id", $this->studentBody(['name_en' => 'Mo']))->assertOk();
        $this->putJson("/api/students/$id", $this->studentBody(['name_en' => 'Moe']))->assertOk();

        $this->assertSame(1, DB::table('guardian_student')->where('student_id', $id)->where('is_primary', true)->count());
    }

    public function test_replacing_the_primary_parent_leaves_other_linked_parents_alone(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->studentBody())->json('data.id');
        $second = User::factory()->role(Role::Guardian)->create(['email' => 'aunt@mail.test']);
        Student::find($id)->guardians()->attach($second->id, ['is_primary' => false]);

        $this->putJson("/api/students/$id", $this->studentBody(['guardian_email' => 'mum@mail.test', 'guardian_phone' => '0599111333']))->assertOk();

        $emails = Student::find($id)->guardians()->pluck('email')->sort()->values()->all();
        $this->assertSame(['aunt@mail.test', 'mum@mail.test'], $emails);
    }

    public function test_a_blank_parent_email_on_update_changes_nothing_and_the_primary_parent_is_listed_first(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/students', $this->studentBody())->json('data.id');
        $other = User::factory()->role(Role::Guardian)->create(['email' => 'a-first@mail.test']);
        Student::find($id)->guardians()->attach($other->id, ['is_primary' => false]);

        $this->putJson("/api/students/$id", $this->studentBody(['guardian_email' => null, 'guardian_name' => null, 'guardian_phone' => null]))
            ->assertOk()->assertJsonCount(2, 'data.guardians')->assertJsonPath('data.guardians.0.email', 'dad@mail.test');
    }
}
