<?php

declare(strict_types=1);

namespace App\Central\Services;

use App\Central\Jobs\PrepareExit;
use App\Central\Models\SchoolExitStep;
use App\Central\Models\Tenant;
use App\Central\Support\SchoolState;
use App\Central\Support\SchoolStatus;
use DomainException;
use RuntimeException;

/**
 * Opens and extends a school's exit window. Opening only queues the preparation (archive + handover package); the window itself
 * starts in PrepareExit, once both are ready. Nothing here deletes school data.
 */
class ExitWindow
{
    public const DEFAULT_DAYS = 14;

    public function __construct(private readonly CentralAudit $audit) {}

    /** @throws DomainException while a window is open or a step is still pending or running */
    public function open(Tenant $school, int $days = self::DEFAULT_DAYS): void
    {
        $busy = SchoolStatus::of($school) === SchoolState::ExitWindow
            || SchoolExitStep::where('tenant_id', $school->getTenantKey())->whereIn('status', ['pending', 'running'])->exists();
        if ($busy) {
            throw new DomainException('An exit window is already open or being prepared.');
        }

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
