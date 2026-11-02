<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Backup\BackupService;
use App\Shared\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

class BackupTest extends TenantTestCase
{
    private BackupService $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        Storage::fake('local');
        config(['backup.disk' => 'backups', 'backup.password' => null]);
        $this->backup = app(BackupService::class);
        $parent = User::factory()->role(Role::Guardian)->create(['name' => 'Abu Ali', 'email' => 'abu@example.com']);
        $ali = Student::create(['student_number' => 'S-1', 'name_ar' => 'علي', 'name_en' => 'Ali', 'gender' => 'male']);
        $ali->guardians()->attach($parent->id, ['is_primary' => true]);
    }

    private function snapshot(): array
    {
        return [
            'students' => Student::orderBy('id')->get(['id', 'student_number', 'name_ar'])->toArray(),
            'users' => User::orderBy('id')->pluck('email')->all(),
            'links' => DB::table('guardian_student')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_a_backup_restores_the_database_exactly_after_changes_and_deletions(): void
    {
        $before = $this->snapshot();
        $file = $this->backup->build('school1');

        Student::query()->delete();
        User::query()->forceDelete();
        Student::create(['student_number' => 'S-9', 'name_ar' => 'غريب', 'gender' => 'male']);

        $result = $this->backup->restore($file);

        $this->assertSame($before, $this->snapshot());
        $this->assertGreaterThan(0, $result['rows']);
        $this->assertSame(1, DB::table('guardian_student')->count());
        $this->assertSame('abu@example.com', Student::first()->guardians()->first()->email); // relations intact
    }

    public function test_a_backup_is_stored_by_school_and_listed_newest_first(): void
    {
        Carbon::setTestNow('2026-11-02 02:30:00');
        $first = $this->backup->store('school1', $this->backup->build('school1'));
        Carbon::setTestNow('2026-11-03 02:30:00');
        $second = $this->backup->store('school1', $this->backup->build('school1'));
        Carbon::setTestNow();

        $this->assertSame('school1/2026-11-02_023000.zip', $first);
        Storage::disk('backups')->assertExists([$first, $second]);
        $this->assertSame([$second, $first], array_column($this->backup->list('school1'), 'path'));
        $this->assertSame([], $this->backup->list('another-school'));
    }

    public function test_the_schools_files_are_saved_and_restored_with_the_data(): void
    {
        Storage::disk('local')->put('homework/a.txt', 'page one');
        $file = $this->backup->build('school1', ['local']);

        Storage::disk('local')->delete('homework/a.txt');
        Storage::disk('local')->put('stray.txt', 'not in the backup');
        $result = $this->backup->restore($file, ['local']);

        $this->assertSame(1, $result['files']);
        $this->assertSame('page one', Storage::disk('local')->get('homework/a.txt'));
        Storage::disk('local')->assertMissing('stray.txt'); // the files are put back to the backup's state
    }

    public function test_a_password_encrypts_the_backup_and_is_needed_to_restore(): void
    {
        $file = $this->backup->build('school1', [], 'S3cret-key');

        $this->assertStringNotContainsString('abu@example.com', file_get_contents($file)); // not readable as plain text
        $zip = new \ZipArchive();
        $zip->open($file);
        $this->assertFalse($zip->getFromName('data/users.jsonl')); // without the password nothing opens
        $zip->close();

        $this->expectExceptionMessage('BACKUP_PASSWORD');
        $this->backup->restore($file); // no password
    }

    public function test_the_right_password_restores(): void
    {
        $before = $this->snapshot();
        $file = $this->backup->build('school1', [], 'S3cret-key');
        Student::query()->delete();

        $this->backup->restore($file, [], 'S3cret-key');
        $this->assertSame($before, $this->snapshot());

        $this->expectExceptionMessage('wrong BACKUP_PASSWORD');
        $this->backup->restore($file, [], 'wrong');
    }

    public function test_a_backup_from_another_database_structure_is_refused_and_nothing_changes(): void
    {
        $file = $this->backup->build('school1');
        DB::table('migrations')->insert(['migration' => '2099_01_01_000001_a_future_change', 'batch' => 99]);
        Student::create(['student_number' => 'S-2', 'name_ar' => 'جديد', 'gender' => 'male']);

        try {
            $this->backup->restore($file);
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('structure changed', $e->getMessage());
        }
        $this->assertSame(2, Student::count()); // untouched
    }

    public function test_a_restore_that_fails_halfway_leaves_the_data_as_it_was(): void
    {
        $file = $this->backup->build('school1');
        // Tamper: a row for the users table that breaks a NOT NULL column.
        $zip = new \ZipArchive();
        $zip->open($file);
        $zip->addFromString('data/users.jsonl', json_encode(['id' => 99, 'name' => null, 'role' => 'admin'])."\n");
        $zip->close();
        Student::create(['student_number' => 'S-2', 'name_ar' => 'جديد', 'gender' => 'male']);

        try {
            $this->backup->restore($file);
            $this->fail('Expected a failure.');
        } catch (\Throwable) {
        }

        $this->assertSame(2, Student::count());
        $this->assertSame('abu@example.com', User::first()->email);
    }

    public function test_old_backups_are_pruned_but_recent_ones_one_per_month_and_the_newest_stay(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');
        config(['backup.keep_days' => 14, 'backup.keep_months' => 6]);
        $disk = Storage::disk('backups');
        $names = ['2026-11-02_023000', '2026-10-25_023000', '2026-10-15_023000', '2026-10-10_023000', '2026-10-02_023000', '2026-09-20_023000', '2026-09-03_023000', '2026-03-01_023000'];
        foreach ($names as $n) {
            $disk->put("school1/$n.zip", 'x');
        }
        $disk->put('school1/2026-08-01_023000_before-restore.zip', 'x'); // a safety copy is never pruned

        $deleted = $this->backup->prune('school1');

        $this->assertEqualsCanonicalizing(['school1/2026-10-15_023000.zip', 'school1/2026-10-10_023000.zip', 'school1/2026-09-20_023000.zip', 'school1/2026-03-01_023000.zip'], $deleted);
        $disk->assertExists(['school1/2026-11-02_023000.zip', 'school1/2026-10-25_023000.zip', 'school1/2026-10-02_023000.zip', 'school1/2026-09-03_023000.zip', 'school1/2026-08-01_023000_before-restore.zip']);
        Carbon::setTestNow();
    }

    public function test_the_newest_backup_is_never_pruned_even_if_it_is_old(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');
        Storage::disk('backups')->put('school1/2025-01-01_023000.zip', 'x');

        $this->assertSame([], $this->backup->prune('school1'));
        Carbon::setTestNow();
    }

    public function test_the_run_command_backs_up_the_central_database_and_status_reports_it(): void
    {
        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->json('data')->nullable();
            $t->timestamps();
        });

        $this->artisan('backup:status')->assertFailed(); // nothing yet: missing
        $this->artisan('backup:run')->assertSuccessful();
        $this->assertCount(1, Storage::disk('backups')->files('_central'));
        $this->artisan('backup:status')->assertSuccessful();
        $this->artisan('backup:list', ['school' => '_central'])->assertSuccessful();

        Carbon::setTestNow(now()->addHours(40));
        $this->artisan('backup:status')->assertFailed(); // a backup that is too old is reported
        Carbon::setTestNow();
    }

    public function test_an_unknown_school_is_reported(): void
    {
        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->json('data')->nullable();
            $t->timestamps();
        });

        $this->artisan('backup:run', ['--school' => 'nope'])->assertFailed();
        $this->artisan('backup:restore', ['school' => 'nope', '--force' => true])->assertFailed();
    }
}
