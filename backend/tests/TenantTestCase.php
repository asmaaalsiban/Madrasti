<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base class for tests that need the tenant schema in a single test database
 * (no tenancy bootstrapping), so only tenant migrations are loaded.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--path' => 'database/migrations/tenant',
        ];
    }
}
