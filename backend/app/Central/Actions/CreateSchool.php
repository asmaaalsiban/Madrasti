<?php

declare(strict_types=1);

namespace App\Central\Actions;

use App\Central\Models\Tenant;
use App\Models\User;
use App\Shared\Enums\Role;
use Throwable;

/**
 * Onboards a school: tenant row -> database + migrations + seeds (TenancyServiceProvider pipeline)
 * -> domain -> first admin. Cleans up if anything fails.
 */
class CreateSchool
{
    /**
     * @param  array{id: string, name: string, domain: string, admin_name: string, admin_email: string, admin_password: string, primary_color?: ?string, default_locale?: ?string}  $data
     */
    public function handle(array $data): Tenant
    {
        $tenant = null;

        try {
            $tenant = Tenant::create([
                'id' => $data['id'],
                'name' => $data['name'],
                'primary_color' => $data['primary_color'] ?? null,
                'default_locale' => $data['default_locale'] ?? 'ar',
                'is_active' => true,
            ]);

            $tenant->domains()->create(['domain' => $data['domain']]);

            $tenant->run(function () use ($data): void {
                User::create([
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'password' => $data['admin_password'],
                    'role' => Role::Admin,
                    'is_active' => true,
                ]);
            });

            return $tenant;
        } catch (Throwable $e) {
            // Deleting the tenant drops its database (DeleteDatabase job) and domains.
            $tenant?->delete();

            throw $e;
        }
    }
}
