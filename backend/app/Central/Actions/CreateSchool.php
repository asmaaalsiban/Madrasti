<?php

declare(strict_types=1);

namespace App\Central\Actions;

use App\Central\Models\Tenant;
use App\Models\User;
use App\Shared\Enums\Role;
use Throwable;

/**
 * Onboards a school: tenant row -> database + migrations + seeds (TenancyServiceProvider pipeline)
 * -> optional domain -> first admin. Cleans up if anything fails.
 */
class CreateSchool
{
    /**
     * @param  array{id: string, name: string, domain?: ?string, admin_name: string, admin_email: string, admin_password: string, primary_color?: ?string, default_locale?: ?string}  $data
     */
    public function handle(array $data): Tenant
    {
        // Only clean up what this call created: never delete a school that already had this id.
        $alreadyExisted = Tenant::whereKey($data['id'])->exists();
        $tenant = null;

        try {
            $tenant = Tenant::create([
                'id' => $data['id'],
                'name' => $data['name'],
                'primary_color' => $data['primary_color'] ?? null,
                'default_locale' => $data['default_locale'] ?? 'ar',
                'is_active' => true,
            ]);

            if (! empty($data['domain'])) {
                $tenant->domains()->create(['domain' => $data['domain']]);
            }

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
            // Tenant::create() runs the database/migration/seed pipeline, so it can fail after the row exists
            // and before $tenant is assigned: look it up by id. Deleting drops its database and domains.
            if (! $alreadyExisted) {
                try {
                    ($tenant ?? Tenant::find($data['id']))?->delete();
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }

            throw $e;
        }
    }
}
