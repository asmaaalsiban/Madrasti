<?php

declare(strict_types=1);

namespace App\Central\Console;

use App\Central\Models\SuperAdmin;
use Illuminate\Console\Command;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'super-admin:create {email} {--name=Super Admin} {--password=}';

    protected $description = 'Create a Super Admin in the central database';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (min 8 chars)');

        if (strlen((string) $password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        if (SuperAdmin::where('email', $this->argument('email'))->exists()) {
            $this->error('A Super Admin with this email already exists.');

            return self::FAILURE;
        }

        SuperAdmin::create([
            'name' => $this->option('name'),
            'email' => $this->argument('email'),
            'password' => $password,
        ]);

        $this->info('Super Admin created.');

        return self::SUCCESS;
    }
}
