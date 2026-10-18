<?php

namespace Tests\Concerns;

/**
 * Feature tests run against the tenant schema in a single test database
 * (no tenancy bootstrapping), so only tenant migrations are loaded.
 */
trait UsesTenantSchema
{
    protected function migrateFreshUsing(): array
    {
        return [
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--path' => 'database/migrations/tenant',
            '--realpath' => false,
        ];
    }
}
