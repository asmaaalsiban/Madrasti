<?php

namespace Tests\Feature\Central;

use App\Central\Models\SuperAdmin;
use App\Mail\SuperAdminLoginCodeMail;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Super Admin sign-in is two steps: email + password, then the 6-digit code e-mailed to the admin. Only the second step issues a token. */
class SuperAdminLoginTest extends TestCase
{
    use DatabaseMigrations;

    private SuperAdmin $admin;

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => $this->shouldDropViews(), '--drop-types' => $this->shouldDropTypes(), '--path' => 'database/migrations/central'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = SuperAdmin::create(['name' => 'Owner', 'email' => 'owner@madrasati.test', 'password' => 'a-very-long-password']);
    }

    private function login(string $password = 'a-very-long-password', string $email = 'owner@madrasati.test')
    {
        return $this->postJson('/api/central/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** Signs in with the password and returns the code that was mailed. */
    private function codeSent(): string
    {
        $this->login()->assertOk();
        $code = null;
        Mail::assertSent(SuperAdminLoginCodeMail::class, function (SuperAdminLoginCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    private function verify(string $code, string $email = 'owner@madrasati.test')
    {
        return $this->postJson('/api/central/auth/verify', ['email' => $email, 'code' => $code]);
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_a_right_password_sends_a_six_digit_code_and_returns_a_challenge(): void
    {
        $this->login()->assertOk()->assertExactJson(['challenge' => true])->assertJsonMissingPath('token');

        Mail::assertSent(SuperAdminLoginCodeMail::class, fn ($m) => $m->hasTo('owner@madrasati.test') && preg_match('/^\d{6}$/', $m->code) === 1);
        $this->assertNotSame($this->codeInDb(), Mail::sent(SuperAdminLoginCodeMail::class)->first()->code, 'The code is stored hashed.');
    }

    private function codeInDb(): string
    {
        return (string) DB::table('super_admin_login_codes')->value('code_hash');
    }

    public function test_the_right_code_returns_a_token_that_expires_in_eight_hours(): void
    {
        $code = $this->codeSent();

        $res = $this->verify($code)->assertOk()->assertJsonPath('user.email', 'owner@madrasati.test');
        $this->assertNotEmpty($res->json('token'));

        $token = $this->admin->tokens()->first();
        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, $token->expires_at->timestamp, 5);
    }

    public function test_a_wrong_password_sends_no_code_and_answers_like_an_unknown_email(): void
    {
        $known = $this->login('not-the-password')->assertOk()->assertExactJson(['challenge' => true]);
        $unknown = $this->login('not-the-password', 'nobody@madrasati.test')->assertOk();

        $this->assertSame($known->getContent(), $unknown->getContent(), 'the answer must not tell which emails are Super Admins');
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('super_admin_login_codes')->count());
    }

    public function test_an_unknown_email_gets_the_same_challenge_and_no_mail(): void
    {
        $this->login('whatever-password', 'nobody@madrasati.test')->assertOk()->assertExactJson(['challenge' => true]);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('super_admin_login_codes')->count());
    }

    public function test_a_wrong_code_counts_and_the_fifth_wrong_try_locks_the_code(): void
    {
        $code = $this->codeSent();

        for ($i = 1; $i <= 5; $i++) {
            $this->verify($this->wrong($code))->assertStatus(422);
        }
        $this->assertSame(5, (int) DB::table('super_admin_login_codes')->value('attempts'));

        // Locked: even the right code is refused now.
        $this->verify($code)->assertStatus(422);
        $this->assertSame(0, $this->admin->tokens()->count());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->codeSent();

        $this->travel(11)->minutes();

        $this->verify($code)->assertStatus(422);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $code = $this->codeSent();

        $this->verify($code)->assertOk();
        $this->verify($code)->assertStatus(422);
    }

    public function test_a_new_code_invalidates_the_old_one(): void
    {
        $first = $this->codeSent();
        Mail::fake();
        $second = $this->codeSent();

        $this->assertSame(1, DB::table('super_admin_login_codes')->count(), 'Only the newest code is kept.');
        if ($first !== $second) { // two random codes collide once in a million
            $this->verify($first)->assertStatus(422);
        }
        $this->verify($second)->assertOk();
    }

    public function test_every_failed_verification_gives_the_same_answer(): void
    {
        $code = $this->codeSent();
        $unknown = $this->verify($code, 'nobody@madrasati.test');
        $wrong = $this->verify($this->wrong($code));
        $this->travel(11)->minutes();
        $expired = $this->verify($code);

        foreach ([$unknown, $wrong, $expired] as $res) {
            $res->assertStatus(422);
        }
        $this->assertSame($unknown->json(), $wrong->json());
        $this->assertSame($unknown->json(), $expired->json());
    }

    public function test_the_token_stops_working_after_eight_hours(): void
    {
        $token = $this->verify($this->codeSent())->json('token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/central/auth/me', ['Authorization' => "Bearer $token"])->assertOk();

        $this->travel(9)->hours();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/central/auth/me', ['Authorization' => "Bearer $token"])->assertUnauthorized();
    }

    public function test_creating_a_super_admin_needs_a_password_of_14_characters(): void
    {
        $this->artisan('super-admin:create', ['email' => 'new@madrasati.test', '--password' => 'short-pass-13'])
            ->expectsOutputToContain('14')->assertFailed();
        $this->assertDatabaseMissing('super_admins', ['email' => 'new@madrasati.test']);

        $this->artisan('super-admin:create', ['email' => 'new@madrasati.test', '--password' => 'long-enough-pass'])->assertSuccessful();
        $this->assertDatabaseHas('super_admins', ['email' => 'new@madrasati.test']);
    }

    public function test_creating_a_super_admin_refuses_an_empty_password(): void
    {
        $this->artisan('super-admin:create', ['email' => 'empty@madrasati.test'])
            ->expectsQuestion('Password (min 14 chars)', '')->assertFailed();
        $this->assertDatabaseMissing('super_admins', ['email' => 'empty@madrasati.test']);
    }

    public function test_the_code_request_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login()->assertOk();
        }

        $this->login()->assertStatus(429);
    }

    public function test_code_tries_are_throttled(): void
    {
        $code = $this->codeSent();

        for ($i = 0; $i < 10; $i++) {
            $this->verify($this->wrong($code));
        }

        $this->verify($code)->assertStatus(429);
    }
}
