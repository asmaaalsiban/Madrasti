<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Enums\Role;
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
}
