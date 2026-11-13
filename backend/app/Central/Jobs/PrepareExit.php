<?php

declare(strict_types=1);

namespace App\Central\Jobs;

use App\Central\Models\SchoolExitStep;
use App\Central\Models\Tenant;
use App\Central\Services\CentralAudit;
use App\Central\Services\ExitWindow;
use App\Central\Support\SchoolState;
use App\Central\Support\SchoolStatus;
use App\Shared\Backup\BackupService;
use App\Shared\Handover\HandoverPackage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class PrepareExit implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public const TIMEOUT = 3600;

    /** A step untouched for this long has no job behind it any more. */
    public const STALE_AFTER = self::TIMEOUT + 600;

    public int $timeout = self::TIMEOUT;

    /** The unique lock expires with the staleness window, so a leaked lock cannot block a retry for ever. */
    public int $uniqueFor = self::STALE_AFTER;

    public function __construct(public readonly string $tenantId) {}

    /** One preparation per school at a time. */
    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /** The job died (crash, timeout): nothing unfinished may stay "running" forever, the owner retries it. */
    public function failed(Throwable $e): void
    {
        SchoolExitStep::where('tenant_id', $this->tenantId)->whereIn('status', ['pending', 'running'])
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
    }

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

        // Both ready and the window not opened yet for this request: open it (also when an earlier run died right after the last step).
        // Once opened the request is cleared, so a repeated job changes nothing and never shifts an open window.
        $school = $school->fresh();
        $ready = SchoolExitStep::where('tenant_id', $code)->where('status', 'done')->count() === count(SchoolExitStep::STEPS);
        if (! $ready || ! $school->exit_window_requested || SchoolStatus::of($school) === SchoolState::ExitWindow) {
            return;
        }

        $days = (int) ($school->exit_window_days ?: ExitWindow::DEFAULT_DAYS);
        $school->update(['exit_window_ends_at' => now()->addDays($days), 'exit_window_requested' => false]);
        $audit->record('exit_window.open', $code, ['days' => $days]);
    }

    /** Runs one step that is waiting (pending, or failed and retried); returns whether it finished the step now. */
    private function run(string $code, string $step, callable $work): bool
    {
        // Claim the step in one statement: a second job (or a step already done or running) gets 0 rows and skips it.
        $claimed = SchoolExitStep::where('tenant_id', $code)->where('step', $step)->where('status', 'pending')->update(['status' => 'running', 'error' => null]);
        if ($claimed === 0) {
            return false;
        }

        $row = SchoolExitStep::where('tenant_id', $code)->where('step', $step)->firstOrFail();
        try {
            $path = $work();
        } catch (Throwable $e) {
            Log::error("Exit step $step of $code failed: ".$e->getMessage(), ['exception' => $e]);
            $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);

            return false;
        }
        $row->update(['status' => 'done', 'path' => $path, 'error' => null, 'finished_at' => now()]);

        return true;
    }
}
