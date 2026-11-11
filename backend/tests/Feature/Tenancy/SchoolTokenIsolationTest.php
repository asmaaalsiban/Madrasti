<?php

namespace Tests\Feature\Tenancy;

use App\Central\Actions\CreateSchool;
use App\Central\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;
use Throwable;

/**
 * A sign-in token belongs to one school. Two real schools (each with its own database) are created, and a token of the first is
 * used against the second: it must be refused, even when both schools have an account with the same email and password.
 */
class SchoolTokenIsolationTest extends TestCase
{
    use DatabaseMigrations;

    /** @var list<string> */
    private array $created = [];

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => $this->shouldDropViews(), '--drop-types' => $this->shouldDropTypes(), '--path' => 'database/migrations/central'];
    }

    protected function tearDown(): void
    {
        // Drops each school's database (a file when the tests run on SQLite), so nothing is left behind.
        foreach ($this->created as $id) {
            try {
                Tenant::find($id)?->delete();
            } catch (Throwable) {
                // The database may already be gone.
            }
        }
        parent::tearDown();
    }

    private function school(string $id): void
    {
        $this->created[] = $id;
        app(CreateSchool::class)->handle([
            'id' => $id, 'name' => "School $id", 'admin_name' => 'Admin', 'admin_email' => 'admin@same.test', 'admin_password' => 'Secret12345',
        ]);
    }

    /**
     * Every real request starts with nothing remembered. A test keeps one application for all its requests, so the guard's cached user and
     * the school that was open are dropped first; otherwise a second request would reuse the first one's user and prove nothing.
     */
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    /** Signs in to a school and returns the token. */
    private function token(string $school): string
    {
        $this->fresh();

        return $this->postJson('/api/auth/login', ['login' => 'admin@same.test', 'password' => 'Secret12345'], ['X-School' => $school])
            ->assertOk()->json('token');
    }

    private function me(string $token, string $school)
    {
        $this->fresh();

        return $this->getJson('/api/auth/me', ['Authorization' => "Bearer $token", 'X-School' => $school]);
    }

    public function test_a_token_of_one_school_is_refused_by_another_school_even_with_the_same_account(): void
    {
        $this->school('schoola');
        $this->school('schoolb');

        $tokenA = $this->token('schoola');
        $tokenB = $this->token('schoolb');

        // Each token works in its own school (the control: the check below would pass trivially if tokens never worked).
        $this->me($tokenA, 'schoola')->assertOk()->assertJsonPath('data.email', 'admin@same.test');
        $this->me($tokenB, 'schoolb')->assertOk();

        // And in no other.
        $this->me($tokenA, 'schoolb')->assertUnauthorized();
        $this->me($tokenB, 'schoola')->assertUnauthorized();
    }

    public function test_a_token_is_not_accepted_when_the_school_header_is_missing_or_unknown(): void
    {
        $this->school('schoola');
        $token = $this->token('schoola');

        $this->fresh();
        $this->getJson('/api/auth/me', ['Authorization' => "Bearer $token"])->assertNotFound();
        $this->me($token, 'nosuchschool')->assertNotFound();
    }
}
