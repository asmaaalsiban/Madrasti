<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Central\Models\Tenant;
use App\Shared\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Backs up the central database and every school (its database and files). One school failing never stops the others;
 * failures are logged, emailed to BACKUP_ALERT_EMAIL, and make the command exit with an error. Scheduled every night.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--school= : Only this school (its code)} {--no-central : Skip the central database}';

    protected $description = 'Back up the central database and every school (database + files)';

    public function handle(BackupService $backup): int
    {
        $password = config('backup.password') ?: null;
        $failures = [];

        if (! $this->option('school') && ! $this->option('no-central')) {
            $this->attempt('_central', $failures, fn () => $backup->store('_central', $backup->build('_central', [], $password)));
        }

        $schools = Tenant::query()->when($this->option('school'), fn ($q, $id) => $q->whereKey($id))->get();
        if ($this->option('school') && $schools->isEmpty()) {
            $this->error('There is no school with this code.');

            return self::FAILURE;
        }
        foreach ($schools as $school) {
            $this->attempt((string) $school->getTenantKey(), $failures, function () use ($school, $backup, $password) {
                // Built inside the school (its database and files), stored outside it.
                $file = $school->run(fn () => $backup->build((string) $school->getTenantKey(), config('backup.file_disks'), $password));

                return $backup->store((string) $school->getTenantKey(), $file);
            });
        }

        if ($failures !== []) {
            $this->notifyFailure($failures);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param array<string, string> $failures */
    private function attempt(string $label, array &$failures, callable $work): void
    {
        try {
            $this->info("$label: ".$work());
        } catch (Throwable $e) {
            $failures[$label] = $e->getMessage();
            Log::error("Backup of $label failed: ".$e->getMessage(), ['exception' => $e]);
            $this->error("$label: FAILED - ".$e->getMessage());
        }
    }

    /** @param array<string, string> $failures */
    private function notifyFailure(array $failures): void
    {
        $to = config('backup.alert_email');
        if (! $to) {
            return;
        }
        $lines = collect($failures)->map(fn ($message, $label) => "- $label: $message")->implode("\n");
        try {
            Mail::raw("These backups failed on ".now()->toDateTimeString().":\n\n$lines", fn ($m) => $m->to($to)->subject(config('app.name').': BACKUP FAILED'));
        } catch (Throwable $e) {
            Log::error('Could not send the backup alert: '.$e->getMessage());
        }
    }
}
