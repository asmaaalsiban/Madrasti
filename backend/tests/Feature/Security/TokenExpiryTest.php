<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Shared\Enums\Role;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Tests\TenantTestCase;

/** A school sign-in lasts 30 days. */
class TokenExpiryTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([InitializeTenancyByRequestData::class]);
    }

    private function me(string $token)
    {
        app('auth')->forgetGuards(); // every request starts with nothing remembered

        return $this->withToken($token)->getJson('/api/auth/me');
    }

    public function test_the_default_is_thirty_days(): void
    {
        $this->assertSame(60 * 24 * 30, config('sanctum.expiration'));
    }

    public function test_a_token_works_inside_the_thirty_days_and_stops_after(): void
    {
        $user = User::factory()->role(Role::Teacher)->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->me($token)->assertOk();

        $this->travel(29)->days();
        $this->me($token)->assertOk();

        $this->travel(2)->days();
        $this->me($token)->assertUnauthorized();
    }

    public function test_signing_in_again_gives_a_working_token(): void
    {
        $user = User::factory()->role(Role::Teacher)->create(['email' => 't@x.test']);
        $old = $user->createToken('web')->plainTextToken;
        $this->travel(31)->days();
        $this->me($old)->assertUnauthorized();

        app('auth')->forgetGuards();
        $new = $this->postJson('/api/auth/login', ['login' => 't@x.test', 'password' => 'password'])->assertOk()->json('token');

        $this->me($new)->assertOk();
    }
}
