<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Tests\TenantTestCase;

class ChangePasswordTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([InitializeTenancyByRequestData::class]);
    }

    private function userWithToken(array $attributes = []): array
    {
        $user = User::factory()->role(Role::Student)->create([...['password' => '123456789'], ...$attributes]);

        return [$user, $user->createToken('web')->plainTextToken];
    }

    private function body(array $override = []): array
    {
        return [...['current_password' => '123456789', 'password' => 'MyOwnPass1', 'password_confirmation' => 'MyOwnPass1'], ...$override];
    }

    public function test_the_default_password_comes_from_config(): void
    {
        $this->assertSame('123456789', config('accounts.default_password'));
    }

    public function test_user_changes_their_password_and_other_sessions_are_signed_out(): void
    {
        [$user, $token] = $this->userWithToken(['must_change_password' => true]);
        $other = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/change-password', $this->body())
            ->assertOk()->assertJsonPath('data.must_change_password', false);

        $user->refresh();
        $this->assertTrue(Hash::check('MyOwnPass1', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertSame(1, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_rejects_a_wrong_current_password(): void
    {
        [, $token] = $this->userWithToken();

        $this->withToken($token)->postJson('/api/auth/change-password', $this->body(['current_password' => 'nope']))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_rejects_the_same_password_the_default_and_weak_or_unconfirmed_ones(): void
    {
        [, $token] = $this->userWithToken();
        $same = ['password' => '123456789', 'password_confirmation' => '123456789'];

        $this->withToken($token)->postJson('/api/auth/change-password', $this->body($same))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withToken($token)->postJson('/api/auth/change-password', $this->body(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withToken($token)->postJson('/api/auth/change-password', $this->body(['password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_the_default_password_is_refused_even_when_the_current_one_is_different(): void
    {
        [, $token] = $this->userWithToken(['password' => 'SomethingElse1']);

        $this->withToken($token)->postJson('/api/auth/change-password', $this->body([
            'current_password' => 'SomethingElse1', 'password' => '123456789', 'password_confirmation' => '123456789',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_guests_cannot_change_a_password(): void
    {
        $this->postJson('/api/auth/change-password', $this->body())->assertUnauthorized();
    }

    public function test_is_throttled_to_five_attempts_a_minute(): void
    {
        [, $token] = $this->userWithToken();

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson('/api/auth/change-password', $this->body(['current_password' => 'nope']))->assertUnprocessable();
        }

        $this->withToken($token)->postJson('/api/auth/change-password', $this->body(['current_password' => 'nope']))->assertStatus(429);
    }

    public function test_a_user_who_must_change_the_password_is_blocked_from_the_api_until_they_do(): void
    {
        $admin = User::factory()->role(Role::Admin)->create(['password' => '123456789', 'must_change_password' => true]);
        $token = $admin->createToken('web')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/overview')
            ->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/change-password', $this->body())->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/overview')->assertOk();
    }

    public function test_passwords_never_reach_the_audit_log(): void
    {
        $user = User::factory()->role(Role::Student)->create(['password' => 'Plain-Secret-1']);
        $user->update(['password' => 'Plain-Secret-2']);

        $logs = AuditLog::where('auditable_type', User::class)->get();
        $this->assertCount(2, $logs);
        $json = $logs->toJson();
        $this->assertStringNotContainsString('Plain-Secret', $json);
        $this->assertStringNotContainsString('$2y$', $json);
        $this->assertSame('[hidden]', $logs[0]->new_values['password']);
        $this->assertSame('[hidden]', $logs[1]->new_values['password']);
    }
}
