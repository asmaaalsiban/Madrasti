<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Central\Models\Tenant;
use App\Shared\Backup\BackupService;
use Illuminate\Console\Command;

/** Deletes old backups (see config/backup.php: every one of the last days, the first of each of the last months). */
class BackupPrune extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Delete old backups, keeping the recent ones and one per month';

    public function handle(BackupService $backup): int
    {
        foreach (['_central', ...Tenant::pluck('id')->map(fn ($id) => (string) $id)->all()] as $label) {
            $deleted = $backup->prune($label);
            $this->info("$label: ".count($deleted).' deleted');
        }

        return self::SUCCESS;
    }
}
