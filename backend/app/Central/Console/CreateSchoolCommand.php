<?php

declare(strict_types=1);

namespace App\Central\Console;

use App\Central\Actions\CreateSchool;
use Illuminate\Console\Command;
use Throwable;

class CreateSchoolCommand extends Command
{
    protected $signature = 'school:create
        {id : Short unique id, used for the database name (e.g. school1)}
        {name : School name}
        {domain : Domain (e.g. school1.localhost)}
        {--admin-name=Admin}
        {--admin-email= : First admin email}
        {--admin-password= : First admin password}
        {--color= : Primary color (e.g. #2563eb)}
        {--locale=ar : Default locale (ar|en)}';

    protected $description = 'Create a school: database, migrations, defaults and first admin';

    public function handle(CreateSchool $action): int
    {
        $email = $this->option('admin-email') ?: $this->ask('Admin email');
        $password = $this->option('admin-password') ?: $this->secret('Admin password (min 8 chars)');

        if (! $email || strlen((string) $password) < 8) {
            $this->error('Admin email and a password of at least 8 characters are required.');

            return self::FAILURE;
        }

        try {
            $tenant = $action->handle([
                'id' => $this->argument('id'),
                'name' => $this->argument('name'),
                'domain' => $this->argument('domain'),
                'admin_name' => $this->option('admin-name'),
                'admin_email' => $email,
                'admin_password' => $password,
                'primary_color' => $this->option('color'),
                'default_locale' => $this->option('locale'),
            ]);
        } catch (Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("School '{$tenant->name}' created. Domain: {$this->argument('domain')}");

        return self::SUCCESS;
    }
}
