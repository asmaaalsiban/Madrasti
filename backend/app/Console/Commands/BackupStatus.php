<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Central\Models\Tenant;
use App\Shared\Backup\BackupService;
use Illuminate\Console\Command;

/** Shows the newest backup of the central database and of every school, and fails if one is missing or stale: use it as a health check. */
class BackupStatus extends Command
{
    protected $signature = 'backup:status';

    protected $description = 'Show the newest backup of every school and warn about missing or old ones';

    public function handle(BackupService $backup): int
    {
        $rows = [];
        $bad = false;
        foreach (['_central', ...Tenant::pluck('id')->map(fn ($id) => (string) $id)->all()] as $label) {
            $latest = $backup->list($label)[0] ?? null;
            $stale = $latest === null || $latest['at']->lt(now()->subHours((int) config('backup.stale_hours')));
            $bad = $bad || $stale;
            $rows[] = [$label, $latest ? $latest['at']->toDateTimeString() : '-', $latest ? round($latest['size'] / 1024).' KB' : '-', count($backup->list($label)), $stale ? 'STALE / MISSING' : 'ok'];
        }
        $this->table(['School', 'Newest backup', 'Size', 'Backups', 'State'], $rows);

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
