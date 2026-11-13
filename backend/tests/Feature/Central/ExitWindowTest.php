<?php

namespace Tests\Feature\Central;

use App\Central\Actions\CreateSchool;
use App\Central\Jobs\PrepareExit;
use App\Central\Models\SchoolExitStep;
use App\Central\Models\SuperAdmin;
use App\Central\Models\Tenant;
use App\Central\Services\ExitWindow;
use App\Central\Services\SchoolActions;
use App\Models\User;
use App\Shared\Backup\BackupService;
use App\Shared\Enums\Role;
use App\Shared\Handover\HandoverPackage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/** The exit window: archive + handover package are prepared first, and only then does the window open. */
class ExitWindowTest extends TestCase
{
    use DatabaseMigrations;

    /** @var list<string> */
    private array $created = [];

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => $this->shouldDropViews(), '--drop-types' => $this->shouldDropTypes(), '--path' => 'database/migrations/central'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('backup.disk'));
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            try {
                Tenant::find($id)?->delete();
            } catch (Throwable) {
                // The database may already be gone.
            }
            \Illuminate\Support\Facades\File::deleteDirectory(storage_path("tenant$id")); // the school's files
        }
        parent::tearDown();
    }

    private function school(string $id = 'alpha'): Tenant
    {
        $this->created[] = $id;
        $school = app(CreateSchool::class)->handle([
            'id' => $id, 'name' => "School $id", 'admin_name' => 'Admin', 'admin_email' => "admin@$id.test", 'admin_password' => 'Secret12345',
        ]);
        $school->run(fn () => User::factory()->role(Role::Teacher)->create(['email' => "teacher@$id.test", 'password' => 'Secret12345', 'must_change_password' => false]));

        return $school;
    }

    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    private function token(string $school, string $who = 'admin'): string
    {
        $this->fresh();

        return $this->postJson('/api/auth/login', ['login' => "$who@$school.test", 'password' => 'Secret12345'], ['X-School' => $school])->assertOk()->json('token');
    }

    private function asSchool(string $token, string $school, string $method, string $uri)
    {
        $this->fresh();

        return $this->json($method, $uri, [], ['Authorization' => "Bearer $token", 'X-School' => $school]);
    }

    private function owner(): void
    {
        $this->fresh();
        $admin = SuperAdmin::create(['name' => 'Owner', 'email' => 'owner@madrasati.test', 'password' => 'a-very-long-password']);
        $this->withToken($admin->createToken('test')->plainTextToken);
    }

    /** Opens the window and runs the queued job right now. */
    private function openAndRun(Tenant $school, int $days = 14): void
    {
        Queue::fake();
        app(ExitWindow::class)->open($school, $days);
        $this->runJob($school);
    }

    private function runJob(Tenant $school): void
    {
        $this->fresh();
        app()->call([new PrepareExit((string) $school->getTenantKey()), 'handle']);
    }

    private function breakPackage(): void
    {
        $this->app->bind(HandoverPackage::class, fn () => new class extends HandoverPackage
        {
            public function build(): string
            {
                throw new \RuntimeException('package failed');
            }
        });
    }

    private function stepStatus(string $school, string $step): string
    {
        return SchoolExitStep::where('tenant_id', $school)->where('step', $step)->value('status');
    }

    public function test_opening_the_window_creates_both_steps_and_queues_the_job(): void
    {
        $school = $this->school();
        Queue::fake();

        app(ExitWindow::class)->open($school, 7);

        Queue::assertPushed(PrepareExit::class);
        $this->assertSame('pending', $this->stepStatus('alpha', 'archive'));
        $this->assertSame('pending', $this->stepStatus('alpha', 'package'));
        $this->assertNull(Tenant::find('alpha')->exit_window_ends_at);
    }

    public function test_the_window_starts_only_when_both_steps_are_done(): void
    {
        $school = $this->school();
        $this->openAndRun($school, 10);

        $this->assertSame('done', $this->stepStatus('alpha', 'archive'));
        $this->assertSame('done', $this->stepStatus('alpha', 'package'));
        $ends = Tenant::find('alpha')->exit_window_ends_at;
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $ends->timestamp, 5);

        $disk = Storage::disk(config('backup.disk'));
        $archive = SchoolExitStep::where('step', 'archive')->value('path');
        $package = SchoolExitStep::where('step', 'package')->value('path');
        $this->assertStringStartsWith('archive/alpha/', $archive);
        $this->assertStringStartsWith('handover/alpha/', $package);
        $disk->assertExists($archive);
        $disk->assertExists($package);
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'exit_window.open', 'tenant_id' => 'alpha']);
    }

    public function test_a_failed_package_step_does_not_open_the_window_and_can_be_retried(): void
    {
        $school = $this->school();
        $this->breakPackage();
        $this->openAndRun($school);

        $this->assertSame('done', $this->stepStatus('alpha', 'archive'));
        $this->assertSame('failed', $this->stepStatus('alpha', 'package'));
        $this->assertStringContainsString('package failed', SchoolExitStep::where('step', 'package')->value('error'));
        $this->assertNull(Tenant::find('alpha')->exit_window_ends_at);

        $this->app->forgetInstance(HandoverPackage::class);
        $this->app->bind(HandoverPackage::class, fn () => new HandoverPackage());
        $this->owner();
        Queue::fake();
        $this->postJson('/api/central/tenants/alpha/exit-steps/package/retry')->assertOk();
        Queue::assertPushed(PrepareExit::class);
        $this->assertSame('pending', $this->stepStatus('alpha', 'package'));
        $this->runJob($school);

        $this->assertSame('done', $this->stepStatus('alpha', 'package'));
        $this->assertNotNull(Tenant::find('alpha')->exit_window_ends_at);
    }

    public function test_a_failed_archive_step_does_not_open_the_window(): void
    {
        $school = $this->school();
        $this->app->bind(BackupService::class, fn () => new class extends BackupService
        {
            public function build(string $label, array $fileDisks = [], ?string $password = null): string
            {
                throw new \RuntimeException('archive failed');
            }
        });
        $this->openAndRun($school);

        $this->assertSame('failed', $this->stepStatus('alpha', 'archive'));
        $this->assertSame('done', $this->stepStatus('alpha', 'package'));
        $this->assertNull(Tenant::find('alpha')->exit_window_ends_at);
    }

    public function test_extend_adds_days_to_an_open_window(): void
    {
        $school = $this->school();
        $this->openAndRun($school, 5);
        $before = Tenant::find('alpha')->exit_window_ends_at;

        $this->owner();
        $this->patchJson('/api/central/tenants/alpha/exit-window', ['days' => 3])->assertOk();

        $this->assertEqualsWithDelta($before->copy()->addDays(3)->timestamp, Tenant::find('alpha')->exit_window_ends_at->timestamp, 1);
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'exit_window.extend', 'tenant_id' => 'alpha']);
    }

    public function test_extend_is_refused_when_there_is_no_open_window(): void
    {
        $this->school();
        $this->owner();

        $this->patchJson('/api/central/tenants/alpha/exit-window', ['days' => 3])->assertStatus(422);
    }

    public function test_the_owner_opens_the_window_through_the_api_with_a_chosen_length(): void
    {
        $this->school();
        $this->owner();
        Queue::fake();

        $this->postJson('/api/central/tenants/alpha/exit-window', ['days' => 21])->assertStatus(202);
        Queue::assertPushed(PrepareExit::class);
        $this->getJson('/api/central/tenants/alpha/exit-steps')->assertOk()->assertJsonCount(2, 'data');

        $this->runJob(Tenant::find('alpha'));
        $this->assertEqualsWithDelta(now()->addDays(21)->timestamp, Tenant::find('alpha')->exit_window_ends_at->timestamp, 5);

        $this->postJson('/api/central/tenants/alpha/exit-window', [])->assertStatus(422); // already open
    }

    public function test_the_owner_downloads_the_archive_and_the_package(): void
    {
        $school = $this->school();
        $this->openAndRun($school);
        $this->owner();

        $this->get('/api/central/tenants/alpha/package')->assertOk()->assertDownload();
        $this->get('/api/central/tenants/alpha/archive')->assertOk()->assertDownload();
    }

    public function test_an_administrator_downloads_the_package_in_the_window_and_it_is_audited(): void
    {
        $school = $this->school();
        $token = $this->token('alpha');
        $this->openAndRun($school, 14);

        $info = $this->asSchool($token, 'alpha', 'GET', '/api/exit-export')->assertOk();
        $this->assertTrue($info->json('ready'));
        $this->assertGreaterThan(13 * 86400, $info->json('seconds_left'));
        $this->assertNotNull($info->json('ends_at'));

        $this->asSchool($token, 'alpha', 'GET', '/api/exit-export/package')->assertOk()->assertDownload();

        $row = DB::connection(config('tenancy.database.central_connection'))->table('central_audit_logs')->where('action', 'package_downloaded')->where('tenant_id', 'alpha')->first();
        $this->assertNotNull($row);
        $adminId = $school->run(fn () => User::where('email', 'admin@alpha.test')->value('id'));
        $this->assertSame($adminId, json_decode($row->meta, true)['by']);
    }

    public function test_the_school_answer_for_exit_export_is_404_without_a_window(): void
    {
        $this->school();
        $token = $this->token('alpha');

        $this->asSchool($token, 'alpha', 'GET', '/api/exit-export')->assertNotFound();
    }

    public function test_a_teacher_gets_403_on_the_download(): void
    {
        $school = $this->school();
        $teacher = $this->token('alpha', 'teacher');
        $this->openAndRun($school);

        $this->asSchool($teacher, 'alpha', 'GET', '/api/exit-export/package')->assertForbidden();
        $this->asSchool($teacher, 'alpha', 'GET', '/api/exit-export')->assertForbidden();
    }

    public function test_in_the_window_an_administrator_can_sign_in_and_out_but_a_teacher_cannot_sign_in(): void
    {
        $school = $this->school();
        $this->openAndRun($school);

        $this->fresh();
        $this->postJson('/api/auth/login', ['login' => 'teacher@alpha.test', 'password' => 'Secret12345'], ['X-School' => 'alpha'])
            ->assertForbidden()->assertJsonPath('code', 'school_suspended');

        $token = $this->token('alpha');
        $this->asSchool($token, 'alpha', 'POST', '/api/auth/logout')->assertOk();
    }

    public function test_after_the_window_the_administrator_is_blocked_too(): void
    {
        $school = $this->school();
        $token = $this->token('alpha');
        $this->openAndRun($school);
        $this->asSchool($token, 'alpha', 'GET', '/api/exit-export/package')->assertOk();

        Tenant::find('alpha')->forceFill(['exit_window_ends_at' => Carbon::now()->subMinute()])->save();

        $this->asSchool($token, 'alpha', 'GET', '/api/exit-export/package')->assertForbidden()->assertJsonPath('code', 'school_suspended');
        $this->asSchool($token, 'alpha', 'GET', '/api/auth/me')->assertForbidden()->assertJsonPath('code', 'school_suspended');
    }

    public function test_nothing_is_deleted_the_school_database_and_files_are_untouched(): void
    {
        $school = $this->school();
        $school->run(fn () => Storage::disk('local')->put('keep/me.txt', 'x'));
        $counts = fn () => $school->run(fn () => collect(DB::select("select name from sqlite_master where type = 'table'"))
            ->pluck('name')->reject(fn ($t) => str_starts_with($t, 'sqlite_'))
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all());
        $before = $counts();

        $this->openAndRun($school);
        Tenant::find('alpha')->forceFill(['exit_window_ends_at' => Carbon::now()->subMinute()])->save();

        $this->assertSame($before, $counts());
        $this->assertTrue($school->run(fn () => Storage::disk('local')->exists('keep/me.txt')));
        $this->assertNotNull(Tenant::find('alpha'));
    }

    public function test_reactivating_after_the_window_brings_everybody_back(): void
    {
        $school = $this->school();
        $teacher = $this->token('alpha', 'teacher');
        $this->openAndRun($school);
        Tenant::find('alpha')->forceFill(['exit_window_ends_at' => Carbon::now()->subMinute()])->save();
        $this->asSchool($teacher, 'alpha', 'GET', '/api/auth/me')->assertForbidden();

        $this->fresh();
        app(SchoolActions::class)->reactivate(Tenant::find('alpha'));

        $this->asSchool($teacher, 'alpha', 'GET', '/api/auth/me')->assertOk();
        $this->token('alpha', 'teacher');
    }

    public function test_running_the_job_again_does_not_move_an_open_window_or_audit_twice(): void
    {
        $school = $this->school();
        $this->openAndRun($school, 10);
        $ends = Tenant::find('alpha')->exit_window_ends_at;
        $this->travel(3)->days();

        $this->runJob($school);

        $this->assertTrue(Tenant::find('alpha')->exit_window_ends_at->equalTo($ends));
        $this->assertSame(1, DB::connection(config('tenancy.database.central_connection'))->table('central_audit_logs')->where('action', 'exit_window.open')->count());
    }

    public function test_opening_is_refused_while_a_step_is_pending_or_running_or_the_window_is_open(): void
    {
        $school = $this->school();
        Queue::fake();
        app(ExitWindow::class)->open($school);

        $this->assertThrows(fn () => app(ExitWindow::class)->open($school), \DomainException::class); // pending
        SchoolExitStep::where('step', 'archive')->update(['status' => 'running']);
        $this->assertThrows(fn () => app(ExitWindow::class)->open($school), \DomainException::class); // running
        SchoolExitStep::query()->update(['status' => 'done']);
        $school->update(['exit_window_ends_at' => now()->addDay()]);
        $this->assertThrows(fn () => app(ExitWindow::class)->open($school), \DomainException::class); // window open
        Queue::assertPushed(PrepareExit::class, 1);

        $this->owner();
        SchoolExitStep::query()->update(['status' => 'running']);
        Tenant::find('alpha')->forceFill(['exit_window_ends_at' => null])->save();
        $this->postJson('/api/central/tenants/alpha/exit-window', [])->assertStatus(422);
    }

    public function test_a_second_job_does_not_redo_a_step_that_is_running_or_done(): void
    {
        $school = $this->school();
        Queue::fake();
        app(ExitWindow::class)->open($school);
        SchoolExitStep::where('step', 'archive')->update(['status' => 'running']);
        $this->app->bind(BackupService::class, fn () => new class extends BackupService
        {
            public function build(string $label, array $fileDisks = [], ?string $password = null): string
            {
                throw new \RuntimeException('must not build a second archive');
            }
        });

        $this->runJob($school);

        $this->assertSame('running', $this->stepStatus('alpha', 'archive'));
        $this->assertSame('done', $this->stepStatus('alpha', 'package'));
        $this->assertNull(Tenant::find('alpha')->exit_window_ends_at);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, new PrepareExit('alpha'));
        $this->assertSame('alpha', (new PrepareExit('alpha'))->uniqueId());
    }

    public function test_a_job_that_dies_marks_unfinished_steps_failed_so_they_can_be_retried(): void
    {
        $school = $this->school();
        Queue::fake();
        app(ExitWindow::class)->open($school);
        SchoolExitStep::where('step', 'archive')->update(['status' => 'done', 'path' => 'archive/alpha/x.zip']);
        SchoolExitStep::where('step', 'package')->update(['status' => 'running']);

        (new PrepareExit('alpha'))->failed(new \RuntimeException('worker killed'));

        $this->assertSame('done', $this->stepStatus('alpha', 'archive'));
        $this->assertSame('failed', $this->stepStatus('alpha', 'package'));
        $this->owner();
        $this->postJson('/api/central/tenants/alpha/exit-steps/package/retry')->assertOk();
        $this->postJson('/api/central/tenants/alpha/exit-steps/archive/retry')->assertStatus(422); // a done step is never redone
        $this->assertSame('x.zip', basename(SchoolExitStep::where('step', 'archive')->value('path')));
    }

    public function test_when_the_queue_refuses_the_job_no_step_stays_pending_and_open_can_be_called_again(): void
    {
        $school = $this->school();
        config(['queue.default' => 'database', 'queue.connections.database.table' => 'no_such_jobs_table']); // pushing fails
        $this->assertThrows(fn () => app(ExitWindow::class)->open($school), \Throwable::class);

        $this->assertSame(0, SchoolExitStep::whereIn('status', ['pending', 'running'])->count());

        config(['queue.default' => 'sync']);
        Queue::fake();
        app(ExitWindow::class)->open($school);
        $this->assertSame('pending', $this->stepStatus('alpha', 'archive'));
    }

    public function test_a_stale_running_or_pending_step_can_be_retried_but_a_fresh_one_cannot(): void
    {
        $school = $this->school();
        Queue::fake();
        app(ExitWindow::class)->open($school);
        SchoolExitStep::where('step', 'archive')->update(['status' => 'done', 'path' => 'archive/alpha/x.zip']);
        SchoolExitStep::where('step', 'package')->update(['status' => 'running']);
        $this->owner();

        $this->postJson('/api/central/tenants/alpha/exit-steps/package/retry')->assertStatus(422); // fresh: a job may be working on it

        $this->travel(PrepareExit::STALE_AFTER + 60)->seconds();
        $this->postJson('/api/central/tenants/alpha/exit-steps/package/retry')->assertOk();
        Queue::assertPushed(PrepareExit::class, 2);
        $this->assertSame('pending', $this->stepStatus('alpha', 'package'));
    }

    public function test_both_steps_done_without_a_window_is_opened_by_a_rerun_exactly_once(): void
    {
        $school = $this->school();
        Queue::fake();
        app(ExitWindow::class)->open($school, 6); // a run that died after the last step: both done, no window
        SchoolExitStep::query()->update(['status' => 'done', 'path' => 'x.zip']);
        $this->assertNull(Tenant::find('alpha')->exit_window_ends_at);

        $this->runJob($school);
        $ends = Tenant::find('alpha')->exit_window_ends_at;
        $this->assertEqualsWithDelta(now()->addDays(6)->timestamp, $ends->timestamp, 5);

        $this->travel(2)->days();
        $this->runJob($school);
        $this->assertTrue(Tenant::find('alpha')->exit_window_ends_at->equalTo($ends));
        $this->assertSame(1, DB::connection(config('tenancy.database.central_connection'))->table('central_audit_logs')->where('action', 'exit_window.open')->count());
    }

    public function test_the_queue_waits_longer_than_the_job_may_run_and_the_unique_lock_expires(): void
    {
        $job = new PrepareExit('alpha');
        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan($job->timeout, config("queue.connections.$connection.retry_after"));
        }
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }
}
