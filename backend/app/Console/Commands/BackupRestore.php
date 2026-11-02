<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Central\Models\Tenant;
use App\Shared\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Puts a school back to the state of one of its backups (database and files). It first makes a safety backup of the
 * current state, so a wrong restore can itself be undone. The school's data since the backup is replaced: ask first.
 */
class BackupRestore extends Command
{
    protected $signature = 'backup:restore {school : The school code} {file? : Path of the backup (see backup:list); default: the newest} {--force : Do not ask}';

    protected $description = 'Restore a school from a backup (replaces its current data)';

    public function handle(BackupService $backup): int
    {
        $school = Tenant::find($this->argument('school'));
        if (! $school) {
            $this->error('There is no school with this code.');

            return self::FAILURE;
        }

        $file = $this->argument('file') ?? (collect($backup->list((string) $school->getTenantKey()))->firstWhere('kind', 'daily')['path'] ?? null); // the newest nightly one, not a safety copy
        if (! $file || ! Storage::disk(config('backup.disk'))->exists($file)) {
            $this->error('No such backup. Use backup:list '.$school->getTenantKey().' to see them.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Replace ALL current data of school '{$school->getTenantKey()}' with $file?")) {
            return self::FAILURE;
        }

        $password = config('backup.password') ?: null;
        try {
            // A safety copy of what is there now, in case this is the wrong backup.
            $safety = $school->run(fn () => $backup->build((string) $school->getTenantKey(), config('backup.file_disks'), $password));
            $this->info('Safety backup: '.$backup->store((string) $school->getTenantKey(), $safety, 'before-restore'));

            $local = tempnam(sys_get_temp_dir(), 'rst');
            file_put_contents($local, Storage::disk(config('backup.disk'))->get($file));
            $result = $school->run(fn () => $backup->restore($local, config('backup.file_disks'), $password));
            @unlink($local);
        } catch (Throwable $e) {
            $this->error('Restore failed, nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored {$result['rows']} rows in {$result['tables']} tables and {$result['files']} files.");

        return self::SUCCESS;
    }
}
