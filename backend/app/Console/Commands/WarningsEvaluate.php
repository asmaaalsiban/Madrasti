<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Central\Support\SchoolStatus;
use App\Modules\Discipline\Services\WarningRules;
use Illuminate\Console\Command;

/** Daily, for one school: runs its warning rules. Run for every school with `php artisan tenants:run warnings:evaluate`. */
class WarningsEvaluate extends Command
{
    protected $signature = 'warnings:evaluate';

    protected $description = "Raise the warnings the school's rules call for (this school's database)";

    public function handle(WarningRules $rules): int
    {
        // A suspended, expired or closed school gets no reminders.
        if (SchoolStatus::currentSchoolBlocked()) {
            return self::SUCCESS;
        }

        $r = $rules->evaluate();
        $this->info("{$r['created']} warning(s) sent, {$r['pending']} waiting for approval");

        return self::SUCCESS;
    }
}
