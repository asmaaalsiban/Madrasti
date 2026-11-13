<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Central\Support\SchoolStatus;
use App\Modules\HR\Services\PayrollService;
use Illuminate\Console\Command;

/** Daily, for one school: tells the accountants and the principal about staff contracts ending soon. Run for every school with `php artisan tenants:run contracts:remind`. */
class ContractsRemind extends Command
{
    protected $signature = 'contracts:remind {--days=30 : How many days ahead to warn}';

    protected $description = "Remind about staff contracts that end soon (this school's database)";

    public function handle(PayrollService $payroll): int
    {
        // A suspended, expired or closed school gets no reminders.
        if (SchoolStatus::currentSchoolBlocked()) {
            return self::SUCCESS;
        }

        $this->info($payroll->remindExpiring((int) $this->option('days')).' contract(s) reminded');

        return self::SUCCESS;
    }
}
