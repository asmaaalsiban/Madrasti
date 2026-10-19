<?php

namespace Tests\Feature;

use App\Central\Actions\SeedDemoSchool;
use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

class SeedDemoTest extends TenantTestCase
{
    private function seed_(): array
    {
        return app(SeedDemoSchool::class)->handle('admin@demo.test', 'Admin12345', 'demo.test', 'Teacher12345');
    }

    public function test_it_creates_admin_teachers_and_structure(): void
    {
        $counts = $this->seed_();

        $admin = User::where('email', 'admin@demo.test')->first();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue(Hash::check('Admin12345', $admin->password));
        $this->assertSame(3, User::where('role', Role::Teacher)->count());
        $this->assertSame(['users' => 4, 'academic_years' => 1, 'grade_levels' => 6, 'subjects' => 8, 'classrooms' => 12], $counts);
        $this->assertSame(1, DB::table('academic_years')->where('is_current', true)->count());
        $this->assertSame(3, DB::table('classrooms')->whereNotNull('homeroom_teacher_id')->count());
    }

    public function test_running_twice_does_not_duplicate(): void
    {
        $first = $this->seed_();
        $second = $this->seed_();

        $this->assertSame($first, $second);
    }

    public function test_it_repairs_a_wrong_admin_password(): void
    {
        User::factory()->role(Role::Admin)->create(['email' => 'admin@demo.test', 'password' => 'something-else']);

        $this->seed_();

        $this->assertSame(1, User::where('email', 'admin@demo.test')->count());
        $this->assertTrue(Hash::check('Admin12345', User::where('email', 'admin@demo.test')->first()->password));
    }

    public function test_command_refuses_outside_local_environment(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('school:seed-demo', ['tenant' => 'school2'])
            ->expectsOutputToContain('only allowed when APP_ENV=local')
            ->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_command_reports_unknown_school(): void
    {
        $this->app['env'] = 'local';
        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->json('data')->nullable();
        });

        $this->artisan('school:seed-demo', ['tenant' => 'nope'])->expectsOutputToContain("School 'nope' not found.")->assertFailed();
    }
}
