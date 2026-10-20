<?php

namespace Tests\Feature\Central;

use App\Central\Actions\ResetSchoolAdmin;
use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class ResetSchoolAdminTest extends TenantTestCase
{
    public function test_creates_the_admin_when_missing(): void
    {
        $user = app(ResetSchoolAdmin::class)->handle('Owner@School.test ', 'Secret123');

        $this->assertSame('owner@school.test', $user->email);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('Secret123', $user->password));
    }

    public function test_repairs_an_existing_user_into_an_active_admin_with_the_new_password(): void
    {
        $existing = User::factory()->role(Role::Teacher)->inactive()->create(['email' => 'owner@school.test', 'password' => 'old-password']);

        $user = app(ResetSchoolAdmin::class)->handle('owner@school.test', 'Secret123');

        $this->assertSame($existing->id, $user->id);
        $this->assertSame(1, User::where('email', 'owner@school.test')->count());
        $this->assertSame(Role::Admin, $user->fresh()->role);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue(Hash::check('Secret123', $user->fresh()->password));
    }

    public function test_restores_a_soft_deleted_user(): void
    {
        $gone = User::factory()->role(Role::Admin)->create(['email' => 'owner@school.test']);
        $gone->delete();

        $user = app(ResetSchoolAdmin::class)->handle('owner@school.test', 'Secret123');

        $this->assertSame($gone->id, $user->id);
        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_command_validates_input_before_touching_anything(): void
    {
        $this->artisan('school:reset-admin', ['tenant' => 'x', 'email' => 'not-an-email', '--password' => 'Secret123'])
            ->expectsOutputToContain('valid email')->assertFailed();
        $this->artisan('school:reset-admin', ['tenant' => 'x', 'email' => 'a@b.test', '--password' => 'short'])
            ->expectsOutputToContain('at least 8')->assertFailed();
    }
}
