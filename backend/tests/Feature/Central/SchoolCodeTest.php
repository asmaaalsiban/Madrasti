<?php

namespace Tests\Feature\Central;

use App\Central\Http\Requests\StoreTenantRequest;
use App\Central\Rules\SchoolCode;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TenantTestCase;

class SchoolCodeTest extends TenantTestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function codes(): array
    {
        return [
            'plain' => ['alnoor', true], 'with digits and hyphen' => ['al-noor2', true], 'three chars' => ['a1b', true],
            'uppercase' => ['Al_Noor', false], 'underscore' => ['school_1', false], 'too short' => ['ab', false],
            'trailing hyphen' => ['ab-', false], 'reserved' => ['www', false], 'reserved api' => ['api', false],
        ];
    }

    #[DataProvider('codes')]
    public function test_the_rule_matches_what_the_site_can_type(string $code, bool $valid): void
    {
        $this->assertSame($valid, Validator::make(['code' => $code], ['code' => [new SchoolCode]])->passes());
    }

    public function test_the_super_admin_api_uses_the_same_code_rule_and_does_not_need_a_domain(): void
    {
        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->json('data')->nullable();
        });
        Schema::create('domains', function ($t) {
            $t->increments('id');
            $t->string('domain')->unique();
            $t->string('tenant_id');
        });
        $payload = ['id' => 'alnoor', 'name' => 'Al Noor', 'admin_name' => 'A', 'admin_email' => 'a@a.test', 'admin_password' => 'Secret123'];
        $rules = (new StoreTenantRequest)->rules();

        $this->assertTrue(Validator::make($payload, $rules)->passes());
        $this->assertTrue(Validator::make([...$payload, 'domain' => 'x.example.com'], $rules)->passes());
        $this->assertTrue(Validator::make([...$payload, 'id' => 'Al_Noor'], $rules)->fails());
    }

    public function test_school_create_command_rejects_a_code_the_site_cannot_type(): void
    {
        $this->artisan('school:create', ['id' => 'Al_Noor', 'name' => 'X', '--admin-email' => 'a@a.test', '--admin-password' => 'Secret123'])
            ->expectsOutputToContain('school code')
            ->assertFailed();
    }
}
