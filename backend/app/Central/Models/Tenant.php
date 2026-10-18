<?php

declare(strict_types=1);

namespace App\Central\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A school. Lives in the central database; each school has its own database.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Real columns of the `tenants` table. Anything else goes into the `data` JSON column.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'logo_path',
            'primary_color',
            'default_locale',
            'is_active',
            'subscription_ends_at',
        ];
    }

    // The id is a string slug that we set ourselves (not auto-increment).
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    /**
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        return array_merge(parent::getCasts(), [
            'is_active' => 'boolean',
            'subscription_ends_at' => 'datetime',
        ]);
    }
}
