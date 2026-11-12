<?php

namespace Tests\Feature\Tenancy;

use App\Central\Actions\CreateSchool;
use App\Central\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Throwable;

/**
 * A suspended, expired or closed school is blocked on every request, including for people who are already signed in.
 */
class SchoolBlockedTest extends TestCase
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

    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

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

    private function students(string $token, string $school)
    {
        $this->fresh();

        return $this->getJson('/api/students', ['Authorization' => "Bearer $token", 'X-School' => $school]);
    }

    private function schoolAnswer(string $school)
    {
        $this->fresh();

        return $this->getJson('/api/school', ['X-School' => $school]);
    }

    /** @param array<string, mixed> $attributes */
    private function change(string $school, array $attributes): void
    {
        Tenant::findOrFail($school)->forceFill($attributes)->save();
    }

    public function test_a_suspended_school_blocks_a_token_that_worked_a_moment_ago(): void
    {
        $this->school('schoola');
        $this->school('schoolb');
        $tokenA = $this->token('schoola');
        $tokenB = $this->token('schoolb');

        $this->me($tokenA, 'schoola')->assertOk();

        $this->change('schoola', ['is_active' => false]);

        $this->me($tokenA, 'schoola')->assertForbidden()->assertJsonPath('code', 'school_suspended');
        $this->me($tokenB, 'schoolb')->assertOk();
    }

    public function test_reactivating_lets_the_same_token_work_again(): void
    {
        $this->school('schoola');
        $token = $this->token('schoola');

        $this->change('schoola', ['is_active' => false]);
        $this->me($token, 'schoola')->assertForbidden();

        $this->change('schoola', ['is_active' => true]);
        $this->me($token, 'schoola')->assertOk();
    }

    public function test_an_end_date_of_today_still_works_and_yesterday_blocks(): void
    {
        $this->school('schoola');
        $token = $this->token('schoola');

        $this->change('schoola', ['subscription_ends_at' => Carbon::today()]);
        $this->me($token, 'schoola')->assertOk();

        $this->change('schoola', ['subscription_ends_at' => Carbon::yesterday()]);
        $this->me($token, 'schoola')->assertForbidden()->assertJsonPath('code', 'school_suspended');
    }

    public function test_the_school_answer_still_works_and_says_it_is_suspended(): void
    {
        $this->school('schoola');

        $this->schoolAnswer('schoola')->assertOk()->assertJsonPath('suspended', false)->assertJsonPath('exit_window_ends_at', null);

        $this->change('schoola', ['is_active' => false]);

        $this->schoolAnswer('schoola')->assertOk()->assertJsonPath('suspended', true)->assertJsonPath('exit_window_ends_at', null);
    }

    public function test_in_an_exit_window_me_works_but_the_student_list_is_blocked(): void
    {
        $this->school('schoola');
        $token = $this->token('schoola');

        $ends = Carbon::now()->addDays(10);
        $this->change('schoola', ['is_active' => false, 'exit_window_ends_at' => $ends]);

        $this->me($token, 'schoola')->assertOk();
        $this->students($token, 'schoola')->assertForbidden()->assertJsonPath('code', 'school_suspended');
        $this->schoolAnswer('schoola')->assertOk()->assertJsonPath('suspended', true)
            ->assertJsonPath('exit_window_ends_at', $ends->toIso8601String());
    }

    public function test_a_closed_school_blocks_everything_but_the_school_answer(): void
    {
        $this->school('schoola');
        $token = $this->token('schoola');

        $this->change('schoola', ['is_active' => false, 'closed_at' => Carbon::now()]);

        $this->me($token, 'schoola')->assertForbidden();
        $this->fresh();
        $this->postJson('/api/auth/login', ['login' => 'admin@same.test', 'password' => 'Secret12345'], ['X-School' => 'schoola'])
            ->assertForbidden()->assertJsonPath('code', 'school_suspended');
        $this->schoolAnswer('schoola')->assertOk()->assertJsonPath('suspended', true);
    }

    public function test_the_logo_of_a_blocked_school_is_not_blocked(): void
    {
        $this->school('schoola');
        $this->change('schoola', ['is_active' => false, 'closed_at' => Carbon::now()]);

        $this->fresh();
        $response = $this->get('/api/school/logo', ['X-School' => 'schoola']);

        $this->assertNotSame(403, $response->getStatusCode());
    }
}
