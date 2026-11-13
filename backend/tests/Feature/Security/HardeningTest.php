<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Shared\Enums\Role;
use App\Shared\Security\LoginMonitor;
use App\Shared\Security\ProductionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Tests\TenantTestCase;

/** The protections added for the security review: headers, HTTPS, CORS, rate limit, sign-in monitoring, debug guard. */
class HardeningTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([InitializeTenancyByRequestData::class]);
    }

    public function test_every_api_answer_carries_the_security_headers(): void
    {
        $response = $this->getJson('/api/school');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->getJson('http://localhost/api/school')->assertHeaderMissing('Strict-Transport-Security');
        $this->getJson('https://localhost/api/school')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_plain_http_is_sent_to_https_when_it_is_forced(): void
    {
        config(['app.force_https' => true]);

        $this->get('http://localhost/api/auth/me')->assertStatus(308)->assertRedirect('https://localhost/api/auth/me');
        $this->getJson('https://localhost/api/auth/me')->assertUnauthorized(); // reaches the app: not redirected
    }

    public function test_a_request_the_proxy_says_was_https_is_not_redirected_in_a_loop(): void
    {
        config(['app.force_https' => true]);

        $this->getJson('http://localhost/api/auth/me', ['X-Forwarded-Proto' => 'https'])->assertUnauthorized();
    }

    public function test_http_is_left_alone_when_it_is_not_forced(): void
    {
        config(['app.force_https' => false]);

        $this->getJson('http://localhost/api/auth/me')->assertUnauthorized();
    }

    public function test_only_the_listed_websites_may_call_the_api_from_a_browser(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.com']]);
        $headers = ['Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization'];

        $this->call('OPTIONS', '/api/auth/login', [], [], [], $this->transformHeadersToServerVars($headers + ['Origin' => 'https://app.example.com']))
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
        // For a website that is not listed the browser is told the listed one, which does not match, so it refuses the answer.
        $evil = $this->call('OPTIONS', '/api/auth/login', [], [], [], $this->transformHeadersToServerVars($headers + ['Origin' => 'https://evil.example.net']));
        $this->assertNotSame('https://evil.example.net', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cookies_are_never_allowed_across_websites(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_headers'));
    }

    public function test_signed_in_routes_have_a_rate_limit_per_person(): void
    {
        RateLimiter::for('school-api', fn () => Limit::perMinute(3)->by('test'));
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/subjects')->assertOk();
        }
        $this->getJson('/api/subjects')->assertStatus(429);
    }

    public function test_the_real_limit_is_generous_for_normal_use(): void
    {
        $limit = RateLimiter::limiter('school-api')(request());

        $this->assertGreaterThanOrEqual(120, $limit->maxAttempts);
    }

    public function test_a_failed_sign_in_is_logged_without_the_password_or_the_login(): void
    {
        User::factory()->role(Role::Teacher)->create(['email' => 'teacher@x.test']);
        Log::spy();

        $this->postJson('/api/auth/login', ['login' => 'teacher@x.test', 'password' => 'WrongPassword!1'])->assertStatus(422);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $text = json_encode($context);

            return $message === 'Failed sign-in' && ! str_contains($text, 'WrongPassword') && ! str_contains($text, 'teacher@x.test') && strlen($context['login']) === 12;
        });
    }

    public function test_repeated_failures_from_one_address_raise_one_alert(): void
    {
        Cache::flush();
        Log::spy();

        for ($i = 0; $i < LoginMonitor::ALERT_AT + 3; $i++) {
            LoginMonitor::failed('alpha', 'someone@x.test', '10.0.0.9');
        }
        LoginMonitor::failed('alpha', 'someone@x.test', '10.0.0.10'); // another address does not count

        Log::shouldHaveReceived('alert')->once();
        Log::shouldHaveReceived('warning')->times(LoginMonitor::ALERT_AT + 4);
    }

    public function test_debug_mode_is_forced_off_in_production(): void
    {
        config(['app.debug' => true]);
        $this->app['env'] = 'production';
        Log::spy();

        ProductionGuard::apply($this->app);

        $this->assertFalse(config('app.debug'));
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_debug_mode_is_left_alone_outside_production(): void
    {
        config(['app.debug' => true]);

        ProductionGuard::apply($this->app);

        $this->assertTrue(config('app.debug'));
    }
}
