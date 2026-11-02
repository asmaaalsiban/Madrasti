<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Backup\BackupService;
use Illuminate\Console\Command;

/** Lists the backups of one school (or "_central"), newest first. */
class BackupList extends Command
{
    protected $signature = 'backup:list {school : The school code, or _central}';

    protected $description = 'List the backups of a school';

    public function handle(BackupService $backup): int
    {
        $rows = array_map(fn ($b) => [$b['path'], $b['at']->toDateTimeString(), round($b['size'] / 1024).' KB', $b['kind']], $backup->list($this->argument('school')));
        $this->table(['Path (use it with backup:restore)', 'Made at', 'Size', 'Kind'], $rows);

        return self::SUCCESS;
    }
}
