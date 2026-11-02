<?php

declare(strict_types=1);

namespace App\Shared\Backup;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Backs up and restores the database the application is connected to RIGHT NOW (inside a school's tenancy that is the
 * school's database) together with the school's files, as one zip: a manifest, one JSON-lines file per table, and the
 * files. It uses no database tool, so the same code works on MySQL, PostgreSQL and the SQLite of the tests.
 */
class BackupService
{
    public const FORMAT = 1;

    /**
     * Builds the zip in a temporary file and returns its path. Call it inside the school's tenancy (or on the central
     * connection for the central database), then store() the file.
     *
     * @param  list<string>  $fileDisks  disks whose files are saved too
     */
    public function build(string $label, array $fileDisks = [], ?string $password = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bak');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup file.');
        }
        $temp = [];
        $encrypt = fn (string $name) => $password === null ?: $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
        if ($password !== null) {
            $zip->setPassword($password);
        }

        $counts = [];
        foreach ($this->tables() as $table) {
            $file = tempnam(sys_get_temp_dir(), 'tbl');
            $temp[] = $file;
            $handle = fopen($file, 'wb');
            $rows = 0;
            foreach ($this->rows($table) as $row) {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
                $rows++;
            }
            fclose($handle);
            $zip->addFile($file, "data/$table.jsonl");
            $encrypt("data/$table.jsonl");
            $counts[$table] = $rows;
        }

        $files = [];
        foreach ($fileDisks as $disk) {
            foreach (Storage::disk($disk)->allFiles() as $name) {
                $entry = "files/$disk/$name";
                $zip->addFromString($entry, Storage::disk($disk)->get($name));
                $encrypt($entry);
                $files[] = $entry;
            }
        }

        $manifest = json_encode([
            'format' => self::FORMAT, 'label' => $label, 'created_at' => now()->toIso8601String(), 'app' => config('app.name'),
            'driver' => DB::connection()->getDriverName(), 'migrations' => $this->migrations(), 'tables' => $counts, 'files' => count($files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $zip->addFromString('manifest.json', $manifest);
        $encrypt('manifest.json');

        if (! $zip->close()) {
            throw new RuntimeException('Could not finish the backup file.');
        }
        foreach ($temp as $file) {
            @unlink($file);
        }

        return $path;
    }

    /** Moves a built file to the backup disk as <label>/<date>_<time>[_suffix].zip and returns that path. */
    public function store(string $label, string $tempFile, string $suffix = ''): string
    {
        $name = $label.'/'.now()->format('Y-m-d_His').($suffix !== '' ? "_$suffix" : '').'.zip';
        $stream = fopen($tempFile, 'rb');
        Storage::disk(config('backup.disk'))->put($name, $stream);
        fclose($stream);
        @unlink($tempFile);

        return $name;
    }

    /**
     * Replaces the data of the connected database (and the school's files) with a backup. Refuses a backup made with a
     * different set of migrations, because its rows would not fit the tables. Everything runs in one transaction: a
     * failed restore leaves the data as it was.
     *
     * @param  list<string>  $fileDisks
     * @return array{tables: int, rows: int, files: int}
     */
    public function restore(string $zipFile, array $fileDisks = [], ?string $password = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('This is not a backup file.');
        }
        if ($password !== null) {
            $zip->setPassword($password);
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (! is_array($manifest)) {
            throw new RuntimeException($password === null ? 'The backup cannot be read (is it encrypted? set BACKUP_PASSWORD).' : 'The backup cannot be read (wrong BACKUP_PASSWORD?).');
        }
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('This backup was made by another version of the backup tool.');
        }
        if (($manifest['migrations'] ?? []) !== $this->migrations()) {
            throw new RuntimeException('The database structure changed since this backup was made. Restore it with the matching version of the application, then update.');
        }

        $present = $this->tables();
        $rows = 0;
        DB::transaction(function () use ($zip, $manifest, $present, &$rows): void {
            $this->withoutForeignKeys(function () use ($zip, $manifest, $present, &$rows): void {
                foreach ($present as $table) {
                    if ($table !== 'migrations') {
                        DB::table($table)->delete();
                    }
                }
                foreach (array_keys($manifest['tables']) as $table) {
                    if ($table === 'migrations' || ! in_array($table, $present, true)) {
                        continue;
                    }
                    $stream = $zip->getStream("data/$table.jsonl");
                    $batch = [];
                    while ($stream && ($line = fgets($stream)) !== false) {
                        $batch[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                        if (count($batch) >= 200) {
                            DB::table($table)->insert($batch);
                            $rows += count($batch);
                            $batch = [];
                        }
                    }
                    if ($batch !== []) {
                        DB::table($table)->insert($batch);
                        $rows += count($batch);
                    }
                    if ($stream) {
                        fclose($stream);
                    }
                }
            });
        });

        $files = 0;
        foreach ($fileDisks as $disk) {
            $storage = Storage::disk($disk);
            foreach ($storage->allFiles() as $name) {
                $storage->delete($name);
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (str_starts_with($entry, "files/$disk/")) {
                    $storage->put(substr($entry, strlen("files/$disk/")), (string) $zip->getFromIndex($i));
                    $files++;
                }
            }
        }
        $zip->close();

        return ['tables' => count($manifest['tables']), 'rows' => $rows, 'files' => $files];
    }

    /** The backups of a label (a school id, or "_central"), newest first. @return list<array{path: string, at: \Carbon\Carbon, size: int, kind: string}> */
    public function list(string $label): array
    {
        $disk = Storage::disk(config('backup.disk'));
        $backups = [];
        foreach ($disk->files($label) as $path) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})(?:_([a-z-]+))?\.zip$/', $path, $m)) {
                $backups[] = ['path' => $path, 'at' => \Carbon\Carbon::parse("$m[1] $m[2]:$m[3]:$m[4]"), 'size' => $disk->size($path), 'kind' => $m[5] ?? 'daily'];
            }
        }
        usort($backups, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $backups;
    }

    /**
     * Deletes old daily backups: keeps every one of the last N days and the first of each of the last N months (and
     * always the newest). Backups with a suffix (made before a restore) are never deleted automatically.
     *
     * @return list<string> the deleted paths
     */
    public function prune(string $label, ?\Carbon\Carbon $now = null): array
    {
        $now ??= now();
        $daily = array_values(array_filter($this->list($label), fn ($b) => $b['kind'] === 'daily'));
        $keepFrom = $now->copy()->subDays((int) config('backup.keep_days'))->startOfDay();
        $monthsFrom = $now->copy()->subMonths((int) config('backup.keep_months'))->startOfMonth();

        $firstOfMonth = [];
        foreach (array_reverse($daily) as $b) { // oldest first: the first backup seen in a month is that month's
            $firstOfMonth[$b['at']->format('Y-m')] ??= $b['path'];
        }

        $deleted = [];
        foreach ($daily as $i => $b) {
            $keep = $i === 0 || $b['at']->gte($keepFrom) || ($b['at']->gte($monthsFrom) && ($firstOfMonth[$b['at']->format('Y-m')] ?? null) === $b['path']);
            if (! $keep) {
                Storage::disk(config('backup.disk'))->delete($b['path']);
                $deleted[] = $b['path'];
            }
        }

        return $deleted;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_values(array_map(fn ($t) => $t['name'], array_filter(Schema::getTables(), fn ($t) => ! str_starts_with($t['name'], 'sqlite_'))));
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function rows(string $table): \Generator
    {
        $query = DB::table($table);
        $columns = array_column(Schema::getColumns($table), 'name');
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary']) {
                foreach ($index['columns'] as $column) {
                    $query->orderBy($column);
                }
            }
        }
        if (! $query->orders && $columns !== []) {
            $query->orderBy($columns[0]);
        }
        foreach ($query->cursor() as $row) {
            yield (array) $row;
        }
    }

    /** The migrations this database has run: a backup only fits a database with exactly these. @return list<string> */
    private function migrations(): array
    {
        return Schema::hasTable('migrations') ? DB::table('migrations')->orderBy('id')->pluck('migration')->all() : [];
    }

    private function withoutForeignKeys(callable $work): void
    {
        $driver = DB::connection()->getDriverName();
        $off = match ($driver) {
            'mysql', 'mariadb' => 'SET FOREIGN_KEY_CHECKS=0',
            'sqlite' => 'PRAGMA defer_foreign_keys = ON', // checked at commit, when all rows are back (foreign_keys itself cannot change inside a transaction)
            'pgsql' => "SET session_replication_role = 'replica'",
            default => null,
        };
        $on = match ($driver) {
            'mysql', 'mariadb' => 'SET FOREIGN_KEY_CHECKS=1',
            'sqlite' => 'PRAGMA defer_foreign_keys = OFF',
            'pgsql' => "SET session_replication_role = 'origin'",
            default => null,
        };
        if ($off) {
            DB::statement($off);
        }
        try {
            $work();
        } finally {
            if ($on) {
                DB::statement($on);
            }
        }
    }
}
