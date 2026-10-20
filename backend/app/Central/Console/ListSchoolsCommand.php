<?php

declare(strict_types=1);

namespace App\Central\Console;

use App\Central\Models\Tenant;
use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Console\Command;
use Throwable;

class ListSchoolsCommand extends Command
{
    protected $signature = 'school:list';

    protected $description = 'List schools with their domains and admin emails';

    public function handle(): int
    {
        $rows = Tenant::with('domains')->get()->map(function (Tenant $tenant): array {
            try {
                $admins = $tenant->run(fn () => User::where('role', Role::Admin)->pluck('email')->filter()->implode(', '));
            } catch (Throwable) {
                $admins = '(database not reachable)';
            }

            return [$tenant->getTenantKey(), $tenant->name, $tenant->domains->pluck('domain')->implode(', '), $admins ?: '(no admin)'];
        });

        if ($rows->isEmpty()) {
            $this->warn('No schools yet. Create one with school:create or the /register page.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Domains', 'Admins'], $rows->all());
        $this->line('Open a school at http://<domain>:<vite port>/login. No admin? php artisan school:reset-admin <ID> <email>');

        return self::SUCCESS;
    }
}
