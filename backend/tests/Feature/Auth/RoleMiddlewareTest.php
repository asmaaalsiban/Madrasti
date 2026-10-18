<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesTenantSchema;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase, UsesTenantSchema;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'role:admin,teacher'])
            ->get('/api/_test/staff', fn () => response()->json(['ok' => true]));
    }

    public function test_allowed_role_passes(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Teacher)->create());

        $this->getJson('/api/_test/staff')->assertOk();
    }

    public function test_other_role_is_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());

        $this->getJson('/api/_test/staff')->assertForbidden();
    }
}
