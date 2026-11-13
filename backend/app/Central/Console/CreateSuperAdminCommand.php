<?php

declare(strict_types=1);

namespace App\Central\Console;

use App\Central\Models\SuperAdmin;
use App\Central\Rules\LongPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'super-admin:create {email} {--name=Super Admin} {--password=}';

    protected $description = 'Create a Super Admin in the central database';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (min 14 chars)');

        $check = Validator::make(['password' => $password], ['password' => ['required', new LongPassword]]);
        if ($check->fails()) {
            $this->error($check->errors()->first('password'));

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
