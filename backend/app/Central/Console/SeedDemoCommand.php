<?php

declare(strict_types=1);

namespace App\Central\Console;

use App\Central\Actions\SeedDemoSchool;
use App\Central\Models\Tenant;
use Illuminate\Console\Command;

class SeedDemoCommand extends Command
{
    protected $signature = 'school:seed-demo
        {tenant : School id (e.g. school2)}
        {--admin-email= : Defaults to admin@<tenant>.test}
        {--admin-password=Admin12345}
        {--teacher-password=Teacher12345}';

    protected $description = 'Fill a school with demo data (admin, teachers, year, grades, subjects, classrooms). Local only.';

    public function handle(SeedDemoSchool $seeder): int
    {
        if (! app()->environment('local')) {
            $this->error('Demo data is only allowed when APP_ENV=local.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("School '{$this->argument('tenant')}' not found.");

            return self::FAILURE;
        }

        $id = $tenant->getTenantKey();
        $email = $this->option('admin-email') ?: "admin@{$id}.test";
        $password = (string) $this->option('admin-password');

        $counts = $tenant->run(fn () => $seeder->handle($email, $password, "{$id}.test", (string) $this->option('teacher-password')));

        $this->info('Demo data ready.');
        $this->table(['Table', 'Rows'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line("Admin login: {$email} / {$password}");

        return self::SUCCESS;
    }
}
