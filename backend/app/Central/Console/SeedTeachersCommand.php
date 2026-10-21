<?php

declare(strict_types=1);

namespace App\Central\Console;

use App\Central\Actions\SeedTeachers;
use App\Central\Models\Tenant;
use Illuminate\Console\Command;

class SeedTeachersCommand extends Command
{
    protected $signature = 'school:seed-teachers
        {tenant : School id (e.g. school2)}
        {--password=Teacher12345}
        {--load=24 : Periods a teacher teaches a week}';

    protected $description = 'Create the demo teachers the curriculum needs, per subject and building. Local only.';

    public function handle(SeedTeachers $seeder): int
    {
        if (! app()->environment('local')) {
            $this->error('Demo teachers are only allowed when APP_ENV=local.');

            return self::FAILURE;
        }
        $tenant = Tenant::find($this->argument('tenant'));
        if (! $tenant) {
            $this->error("School '{$this->argument('tenant')}' not found.");

            return self::FAILURE;
        }

        $result = $tenant->run(fn () => $seeder->handle((string) $tenant->getTenantKey(), (string) $this->option('password'), (int) $this->option('load')));
        $this->info("{$result['created']} teacher(s) created.");
        $this->table(['Subject', 'Teachers needed'], collect($result['needed'])->map(fn ($n, $code) => [$code, $n])->values()->all());
        $this->line('Password: '.$this->option('password'));

        return self::SUCCESS;
    }
}
