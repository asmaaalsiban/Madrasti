<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Scheduling\Services\DutyScheduler;
use Illuminate\Console\Command;

/** Daily, for one school: tells the people on duty tomorrow. Run for every school with `php artisan tenants:run duties:remind`. */
class DutiesRemind extends Command
{
    protected $signature = 'duties:remind {--date= : The day to remind about (default tomorrow)}';

    protected $description = "Remind staff of tomorrow's duty (this school's database)";

    public function handle(DutyScheduler $duties): int
    {
        $this->info($duties->remind($this->option('date') ?: null).' person(s) reminded');

        return self::SUCCESS;
    }
}
