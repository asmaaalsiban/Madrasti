<?php

declare(strict_types=1);

namespace App\Central\Console;

use App\Central\Actions\ResetSchoolAdmin;
use App\Central\Models\Tenant;
use Illuminate\Console\Command;

class ResetSchoolAdminCommand extends Command
{
    protected $signature = 'school:reset-admin
        {tenant : School id (see school:list)}
        {email : Admin email to create or repair}
        {--password= : New password (asked if omitted)}';

    protected $description = 'Create or repair the admin of a school and set its password';

    public function handle(ResetSchoolAdmin $action): int
    {
        $email = (string) $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: $this->secret('New password (min 8 chars)'));
        if (strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("School '{$this->argument('tenant')}' not found. Run: php artisan school:list");

            return self::FAILURE;
        }

        $user = $tenant->run(fn () => $action->handle($email, $password));

        $this->info('Admin ready.');
        $this->line('School:   '.$tenant->getTenantKey().' ('.$tenant->domains()->pluck('domain')->implode(', ').')');
        $this->line("Email:    {$user->email}");
        $this->line('Password: the one you just set');

        return self::SUCCESS;
    }
}
