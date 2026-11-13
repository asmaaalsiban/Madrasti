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
use Throwable;

/**
 * Opens and extends a school's exit window. Opening only queues the preparation (archive + handover package); the window itself
 * starts in PrepareExit, once both are ready. Nothing here deletes school data.
 */
class ExitWindow
{
    public const DEFAULT_DAYS = 14;

    public function __construct(private readonly CentralAudit $audit) {}

    /** @throws DomainException while a window is open or a step is being prepared (not stale) */
    public function open(Tenant $school, int $days = self::DEFAULT_DAYS): void
    {
        $busy = SchoolStatus::of($school) === SchoolState::ExitWindow
            || SchoolExitStep::where('tenant_id', $school->getTenantKey())->get()->contains(fn (SchoolExitStep $s) => $s->isActive());
        if ($busy) {
            throw new DomainException('An exit window is already open or being prepared.');
        }

        foreach (SchoolExitStep::STEPS as $step) {
            SchoolExitStep::updateOrCreate(
                ['tenant_id' => $school->getTenantKey(), 'step' => $step],
                ['status' => 'pending', 'path' => null, 'error' => null, 'finished_at' => null],
            );
        }
        // Kept for the job and for a retry: how long the window is, and that this request has not opened it yet.
        $school->update(['exit_window_days' => $days, 'exit_window_requested' => true]);
        $this->audit->record('exit_window.request', $school->getTenantKey(), ['days' => $days]);

        $this->queue($school);
    }

    /** Queues the preparation. If the queue refuses, the waiting steps are marked failed, so the owner can retry instead of being stuck. */
    public function queue(Tenant $school): void
    {
        try {
            PrepareExit::dispatch((string) $school->getTenantKey());
        } catch (Throwable $e) {
            SchoolExitStep::where('tenant_id', $school->getTenantKey())->whereIn('status', ['pending', 'running'])
                ->update(['status' => 'failed', 'error' => mb_substr('Could not queue the job: '.$e->getMessage(), 0, 1000), 'finished_at' => now()]);

            throw $e;
        }
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
