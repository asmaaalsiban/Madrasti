<?php

namespace Tests\Feature\Handover;

use App\Shared\Handover\HandoverPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TenantTestCase;
use ZipArchive;

/**
 * The package is built from raw queries and row mappers that run once per row. A wrong column or property name does not show on an empty
 * school, but would stop the package of every real school (and a retry cannot help). So every table gets one row, all of them related
 * through id 1, and the package must still build and hold data rows.
 */
class PackageWithDataTest extends TenantTestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            is_dir($file) ? $this->rmdir($file) : @unlink($file);
        }
        parent::tearDown();
    }

    private function rmdir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            is_dir($f) ? $this->rmdir($f) : unlink($f);
        }
        rmdir($dir);
    }

    /** First allowed value of a column limited by a CHECK ... IN (...) (SQLite stores enums that way). */
    private function allowed(string $table, string $column): ?string
    {
        $sql = (string) DB::table('sqlite_master')->where('name', $table)->value('sql');

        return preg_match('/"'.preg_quote($column, '/').'" in \(\'([^\']*)\'/i', $sql, $m) ? $m[1] : null;
    }

    private function fillEveryTableWithOneRow(): void
    {
        foreach (Schema::getTables() as $t) {
            $table = $t['name'];
            if (in_array($table, ['migrations', 'sqlite_sequence', 'personal_access_tokens', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'sessions'], true) || str_starts_with($table, 'sqlite_')) {
                continue;
            }
            $row = [];
            foreach (Schema::getColumns($table) as $col) {
                $name = $col['name'];
                $type = strtolower($col['type_name']);
                if ($name === 'id' || str_ends_with($name, '_id') || $name === 'user_id') {
                    $row[$name] = 1;
                } elseif ($col['nullable'] || $col['default'] !== null) {
                    continue;
                } elseif (str_contains($type, 'int') || in_array($type, ['numeric', 'decimal', 'float', 'double', 'real'], true)) {
                    $row[$name] = 1;
                } elseif ($type === 'date') {
                    $row[$name] = '2026-10-05';
                } elseif (in_array($type, ['datetime', 'timestamp'], true)) {
                    $row[$name] = '2026-10-05 08:00:00';
                } elseif ($type === 'time') {
                    $row[$name] = '08:00:00';
                } elseif ($type === 'boolean') {
                    $row[$name] = 1;
                } elseif ($type === 'json') {
                    $row[$name] = '[]';
                } else {
                    $row[$name] = $this->allowed($table, $name) ?? 'x';
                }
            }
            try {
                DB::table($table)->insert($row + ['id' => 1]);
            } catch (\Throwable) {
                try {
                    DB::table($table)->insert($row); // a table without an id column
                } catch (\Throwable) {
                    // a table whose rules this generic filler does not know: left empty
                }
            }
        }
    }

    public function test_the_package_builds_and_holds_rows_when_every_table_has_data(): void
    {
        $this->fillEveryTableWithOneRow();

        $path = app(HandoverPackage::class)->build();
        $this->cleanup[] = $path;
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        $withData = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! str_ends_with($name, '.xlsx')) {
                continue;
            }
            $file = tempnam(sys_get_temp_dir(), 'pd');
            $this->cleanup[] = $file;
            file_put_contents($file, $zip->getFromIndex($i));
            $reader = new Reader();
            $reader->open($file);
            $rows = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $_) {
                    $rows++;
                }
            }
            $reader->close();
            if ($rows > 1) {
                $withData[] = $name;
            }
        }

        // Each of these sections reads its own tables through row mappers: at least one file of each must hold a data row.
        foreach (['people/', 'payroll/', 'academic/'] as $section) {
            $this->assertNotEmpty(array_filter($withData, fn ($f) => str_contains($f, $section)), "no data row reached the files of $section (files with data: ".implode(', ', $withData).')');
        }
    }
}
