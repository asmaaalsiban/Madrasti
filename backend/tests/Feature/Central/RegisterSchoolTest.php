<?php

namespace Tests\Feature\Central;

use App\Central\Actions\CreateSchool;
use App\Central\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TenantTestCase;

class RegisterSchoolTest extends TenantTestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.registration_enabled' => true]);

        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->json('data')->nullable();
        });

        $this->bindAction(function (array $data): Tenant {
            DB::table('tenants')->insert(['id' => $data['id']]);

            return new Tenant(['id' => $data['id']]);
        });
    }

    private function bindAction(callable $handler): void
    {
        $test = $this;
        $this->app->instance(CreateSchool::class, new class($handler, $test) extends CreateSchool
        {
            public function __construct(private $handler, private $test) {}

            public function handle(array $data): Tenant
            {
                $this->test->record($data);

                return ($this->handler)($data);
            }
        });
    }

    public function record(array $data): void
    {
        $this->calls[] = $data;
    }

    private function body(array $override = []): array
    {
        return [...[
            'school_name' => 'مدرسة النور', 'code' => 'alnoor', 'admin_name' => 'Admin',
            'admin_email' => 'admin@alnoor.test', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ], ...$override];
    }

    public function test_registers_a_school(): void
    {
        $this->postJson('/api/central/register', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.code', 'alnoor')
            ->assertJsonPath('data.code', 'alnoor')
            ->assertJsonMissingPath('data.domain');

        $this->assertCount(1, $this->calls);
        $this->assertSame('alnoor', $this->calls[0]['id']);
        $this->assertArrayNotHasKey('domain', $this->calls[0]);
        $this->assertSame('مدرسة النور', $this->calls[0]['name']);
        $this->assertSame('admin@alnoor.test', $this->calls[0]['admin_email']);
        $this->assertSame('Secret123', $this->calls[0]['admin_password']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badCodes(): array
    {
        return [
            'uppercase' => ['Alnoor'], 'underscore' => ['al_noor'], 'leading hyphen' => ['-ab'],
            'trailing hyphen' => ['ab-'], 'too short' => ['ab'], 'too long' => [str_repeat('a', 31)],
            'reserved www' => ['www'], 'reserved admin' => ['admin'], 'reserved api' => ['api'],
        ];
    }

    #[DataProvider('badCodes')]
    public function test_rejects_bad_codes(string $code): void
    {
        $this->postJson('/api/central/register', $this->body(['code' => $code]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertCount(0, $this->calls);
    }

    public function test_rejects_a_taken_code(): void
    {
        DB::table('tenants')->insert(['id' => 'taken']);

        $this->postJson('/api/central/register', $this->body(['code' => 'taken']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertCount(0, $this->calls);
    }

    public function test_password_rules(): void
    {
        $this->postJson('/api/central/register', $this->body(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/central/register', $this->body(['password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_required_fields(): void
    {
        $this->postJson('/api/central/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_name', 'code', 'admin_name', 'admin_email', 'password']);
    }

    public function test_registration_works_on_any_host_because_there_is_one_public_url(): void
    {
        $this->postJson('http://www.example.org/api/central/register', $this->body())->assertCreated();
        $this->assertCount(1, $this->calls);
    }

    public function test_the_website_can_ask_whether_registration_is_on(): void
    {
        config(['tenancy.registration_enabled' => true]);
        $this->getJson('/api/central/registration')->assertOk()->assertExactJson(['enabled' => true]);

        config(['tenancy.registration_enabled' => false]);
        $this->getJson('/api/central/registration')->assertOk()->assertExactJson(['enabled' => false]);
    }

    public function test_registration_is_off_unless_it_is_switched_on(): void
    {
        $this->assertSame('REGISTRATION_ENABLED=false', collect(file(base_path('.env.example'), FILE_IGNORE_NEW_LINES))->first(fn ($l) => str_starts_with($l, 'REGISTRATION_ENABLED=')), 'the example .env must not turn public registration on');
    }

    public function test_disabled_returns_403(): void
    {
        config(['tenancy.registration_enabled' => false]);

        $this->postJson('/api/central/register', $this->body())
            ->assertForbidden()
            ->assertJsonPath('message', __('registration.disabled'));
        $this->assertCount(0, $this->calls);
    }

    public function test_disabled_does_not_reveal_validation_or_taken_codes(): void
    {
        config(['tenancy.registration_enabled' => false]);
        DB::table('tenants')->insert(['id' => 'taken']);

        $this->postJson('/api/central/register', $this->body(['code' => 'taken']))->assertForbidden();
        $this->postJson('/api/central/register', [])->assertForbidden();
    }

    public function test_failure_returns_translated_500_without_leaking_details(): void
    {
        $this->bindAction(fn () => throw new RuntimeException('SQLSTATE secret'));

        $response = $this->postJson('/api/central/register', $this->body())->assertStatus(500);

        $this->assertSame(__('registration.failed'), $response->json('message'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_validation_messages_are_arabic(): void
    {
        $this->postJson('/api/central/register', [], ['Accept-Language' => 'ar'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', __('validation.required', ['attribute' => 'كود المدرسة'], 'ar'));
    }

    public function test_throttles_after_five_requests(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/central/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/central/register', [])->assertStatus(429);
    }

    public function test_spoofed_forwarded_for_does_not_bypass_the_throttle(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->postJson('/api/central/register', [], ['X-Forwarded-For' => "1.2.3.$i"])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/central/register', [], ['X-Forwarded-For' => '1.2.3.99'])
            ->assertStatus(429);
    }

    public function test_global_daily_cap_applies_across_ips(): void
    {
        config(['tenancy.registration_daily_limit' => 2]);

        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/central/register', [])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->postJson('/api/central/register', [])->assertStatus(429);
    }
}
