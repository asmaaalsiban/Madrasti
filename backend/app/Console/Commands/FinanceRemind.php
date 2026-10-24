<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Services\FeeBilling;
use Illuminate\Console\Command;

/**
 * Daily, for one school: refreshes the overdue statuses, then tells the parents about installments due in the next 3 days
 * and about overdue ones (again every 7 days). Run for every school with `php artisan tenants:run finance:remind`.
 */
class FinanceRemind extends Command
{
    protected $signature = 'finance:remind {--days=3 : How many days ahead to warn} {--repeat=7 : Days between two reminders of the same installment}';

    protected $description = "Remind parents of installments that are due soon or overdue (this school's database)";

    public function handle(FeeBilling $billing, Notifier $notifier): int
    {
        $billing->refreshOverdue();
        $until = now()->addDays((int) $this->option('days'))->toDateString();
        $again = now()->subDays((int) $this->option('repeat'));

        $due = Installment::with('fee.student.guardians')->whereColumn('paid_amount', '<', 'amount')->where('due_date', '<=', $until)
            ->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<', $again))->get();

        $sent = 0;
        foreach ($due as $installment) {
            $student = $installment->fee->student;
            $parents = $student->guardians->pluck('id');
            if ($parents->isEmpty()) {
                continue;
            }
            $notifier->toUsers($parents, 'installment', [
                'child_ar' => $student->name_ar, 'child_en' => $student->name_en ?: $student->name_ar, 'amount' => Money::amount($installment->remainingCents()),
                'due' => $installment->due_date->format('Y-m-d'), 'overdue' => $installment->due_date->lt(now()->startOfDay()),
            ], "/parent/children/{$student->id}/fees");
            $installment->update(['last_reminded_at' => now()]);
            $sent++;
        }
        $this->info("Reminded about $sent installments.");

        return self::SUCCESS;
    }
}
