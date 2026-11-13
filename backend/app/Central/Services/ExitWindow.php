<?php

declare(strict_types=1);

namespace App\Central\Services;

use App\Central\Jobs\PrepareExit;
use App\Central\Models\SchoolExitStep;
use App\Central\Models\Tenant;
use RuntimeException;

/**
 * Opens and extends a school's exit window. Opening only queues the preparation (archive + handover package); the window itself
 * starts in PrepareExit, once both are ready. Nothing here deletes school data.
 */
class ExitWindow
{
    public const DEFAULT_DAYS = 14;

    public function __construct(private readonly CentralAudit $audit) {}

    public function open(Tenant $school, int $days = self::DEFAULT_DAYS): void
    {
        foreach (SchoolExitStep::STEPS as $step) {
            SchoolExitStep::updateOrCreate(
                ['tenant_id' => $school->getTenantKey(), 'step' => $step],
                ['status' => 'pending', 'path' => null, 'error' => null, 'finished_at' => null],
            );
        }
        $school->update(['exit_window_days' => $days]); // kept for a retry of a failed step
        $this->audit->record('exit_window.request', $school->getTenantKey(), ['days' => $days]);

        PrepareExit::dispatch((string) $school->getTenantKey());
    }

    public function extend(Tenant $school, int $days): void
    {
        $ends = $school->exit_window_ends_at;
        if ($ends === null || $ends->isPast()) {
            throw new RuntimeException('The school has no open exit window.');
        }

        $school->update(['exit_window_ends_at' => $ends->copy()->addDays($days)]);
        $this->audit->record('exit_window.extend', $school->getTenantKey(), ['days' => $days]);
    }
}
