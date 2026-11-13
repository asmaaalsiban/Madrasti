<?php

declare(strict_types=1);

namespace App\Central\Jobs;

use App\Central\Models\SchoolExitStep;
use App\Central\Models\Tenant;
use App\Central\Services\CentralAudit;
use App\Central\Services\ExitWindow;
use App\Shared\Backup\BackupService;
use App\Shared\Handover\HandoverPackage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prepares what a leaving school takes with it: the full archive and the readable handover package, both stored outside the
 * school's own folders. Each step runs on its own, so one failing never hides the other. The exit window starts only when BOTH
 * are done; a failed step is shown to the owner, who retries it. It never deletes anything of the school.
 */
class PrepareExit implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $tenantId) {}

    public function handle(BackupService $backup, HandoverPackage $package, CentralAudit $audit): void
    {
        $school = Tenant::findOrFail($this->tenantId);
        $code = (string) $school->getTenantKey();

        $this->run($code, SchoolExitStep::ARCHIVE, function () use ($school, $backup, $code): string {
            $file = $school->run(fn () => $backup->build($code, config('backup.file_disks'), config('backup.password') ?: null));

            return $backup->store("archive/$code", $file);
        });

        $this->run($code, SchoolExitStep::PACKAGE, function () use ($school, $backup, $package, $code): string {
            // Account names in the reports follow the school's own language.
            $previous = app()->getLocale();
            app()->setLocale($school->default_locale ?: config('app.locale'));
            try {
                $file = $school->run(fn () => $package->build());
            } finally {
                app()->setLocale($previous);
            }

            return $backup->store("handover/$code", $file);
        });

        $ready = SchoolExitStep::where('tenant_id', $code)->where('status', 'done')->count() === count(SchoolExitStep::STEPS);
        if (! $ready) {
            return;
        }

        $days = (int) ($school->exit_window_days ?: ExitWindow::DEFAULT_DAYS);
        $school->update(['exit_window_ends_at' => now()->addDays($days)]);
        $audit->record('exit_window.open', $code, ['days' => $days]);
    }

    /** Runs one step unless it is already done, and records how it ended. */
    private function run(string $code, string $step, callable $work): void
    {
        $row = SchoolExitStep::where('tenant_id', $code)->where('step', $step)->firstOrFail();
        if ($row->isDone()) {
            return;
        }

        $row->update(['status' => 'running', 'error' => null]);
        try {
            $path = $work();
        } catch (Throwable $e) {
            Log::error("Exit step $step of $code failed: ".$e->getMessage(), ['exception' => $e]);
            $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);

            return;
        }
        $row->update(['status' => 'done', 'path' => $path, 'error' => null, 'finished_at' => now()]);
    }
}
