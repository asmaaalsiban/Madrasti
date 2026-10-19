<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Tests\TenantTestCase;

abstract class AcademicTestCase extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class]);
    }

    protected function actAsAdmin(): User
    {
        $admin = User::factory()->role(Role::Admin)->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @return array<string, array{0: Role}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'teacher' => [Role::Teacher],
            'student' => [Role::Student],
            'parent' => [Role::Guardian],
        ];
    }
}
