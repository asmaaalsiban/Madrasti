<?php

namespace Tests\Feature\Central;

use App\Central\Actions\CreateSchool;
use App\Central\Models\SuperAdmin;
use App\Central\Models\Tenant;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/** The Super Admin's schools API, against real separate schools (each with its own database). */
class SchoolsApiTest extends TestCase
{
    use DatabaseMigrations;

    /** @var list<string> */
    private array $created = [];

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => $this->shouldDropViews(), '--drop-types' => $this->shouldDropTypes(), '--path' => 'database/migrations/central'];
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            try {
                Tenant::find($id)?->delete();
            } catch (Throwable) {
                // The database may already be gone.
            }
        }
        parent::tearDown();
    }

    private function school(string $id, array $extra = []): Tenant
    {
        $this->created[] = $id;

        return app(CreateSchool::class)->handle($extra + [
            'id' => $id, 'name' => "School $id", 'admin_name' => 'Admin', 'admin_email' => "admin@$id.test", 'admin_password' => 'Secret12345',
        ]);
    }

    private function owner(): SuperAdmin
    {
        $admin = SuperAdmin::create(['name' => 'Owner', 'email' => 'owner@madrasati.test', 'password' => 'a-very-long-password']);
        $this->withToken($admin->createToken('test')->plainTextToken); // a real token survives the guard resets between requests

        return $admin;
    }

    /** Every request starts with nothing remembered (see SchoolTokenIsolationTest). */
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    private function listed(string $query = ''): array
    {
        $this->fresh();

        return $this->getJson('/api/central/tenants'.$query)->assertOk()->json('data');
    }

    public function test_only_a_super_admin_can_list_schools(): void
    {
        $this->getJson('/api/central/tenants')->assertUnauthorized();

        $this->school('alpha');
        $token = $this->postJson('/api/auth/login', ['login' => 'admin@alpha.test', 'password' => 'Secret12345'], ['X-School' => 'alpha'])->assertOk()->json('token');
        $this->fresh();
        $this->withToken($token);
        $this->getJson('/api/central/tenants')->assertUnauthorized(); // a school token is not a Super Admin token

        $this->fresh();
        $this->owner();
        $this->getJson('/api/central/tenants')->assertOk();
    }

    public function test_the_list_has_state_end_date_and_student_counts(): void
    {
        $school = $this->school('alpha', ['max_students' => 50, 'subscription_ends_at' => '2099-12-31']);
        $school->run(function () {
            foreach ([1, 2, 3] as $n) {
                Student::create(['student_number' => "S-$n", 'name_ar' => 'طالب', 'gender' => 'male']);
            }
            User::factory()->role(Role::Teacher)->create();
        });
        $this->fresh();
        $this->owner();

        $row = $this->listed()[0];

        $this->assertSame('alpha', $row['id']);
        $this->assertSame('active', $row['state']);
        $this->assertSame(50, $row['max_students']);
        $this->assertStringStartsWith('2099-12-31', $row['subscription_ends_at']);
        $this->assertSame(3, $row['students_count']);
        $this->assertSame(2, $row['staff_count']); // the first admin and the teacher
    }

    public function test_the_list_filters_by_state_and_searches_by_name_or_code(): void
    {
        $this->school('alpha', ['name' => 'Bright Stars']);
        $this->school('beta', ['name' => 'Green Valley']);
        Tenant::find('beta')->update(['is_active' => false]);
        $this->fresh();
        $this->owner();

        $this->assertSame(['beta'], array_column($this->listed('?state=suspended'), 'id'));
        $this->assertSame(['alpha'], array_column($this->listed('?state=active'), 'id'));
        $this->assertSame(['alpha'], array_column($this->listed('?search=bright'), 'id'));
        $this->assertSame(['beta'], array_column($this->listed('?search=bet'), 'id'));
        $this->assertSame([], $this->listed('?search=zzz'));
        $this->fresh();
        $this->getJson('/api/central/tenants?state=nonsense')->assertStatus(422);
    }

    public function test_a_school_is_created_with_a_limit_and_an_end_date(): void
    {
        $this->owner();
        $this->created[] = 'gamma';

        $this->postJson('/api/central/tenants', [
            'id' => 'gamma', 'name' => 'Gamma', 'admin_name' => 'A', 'admin_email' => 'a@gamma.test', 'admin_password' => 'Secret12345',
            'max_students' => 120, 'subscription_ends_at' => '2027-06-30',
        ])->assertCreated()->assertJsonPath('data.max_students', 120)->assertJsonPath('data.state', 'active');

        $tenant = Tenant::find('gamma');
        $this->assertSame(120, $tenant->max_students);
        $this->assertSame('2027-06-30', $tenant->subscription_ends_at->toDateString());
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'school.create', 'tenant_id' => 'gamma']);

        $this->postJson('/api/central/tenants', [
            'id' => 'delta', 'name' => 'D', 'admin_name' => 'A', 'admin_email' => 'a@d.test', 'admin_password' => 'short', 'max_students' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['admin_password', 'max_students']);
    }

    public function test_suspend_then_reactivate_changes_the_state_and_writes_the_audit(): void
    {
        $this->school('alpha');
        $admin = $this->owner();

        $this->postJson('/api/central/tenants/alpha/suspend')->assertOk()->assertJsonPath('data.state', 'suspended');
        $this->assertFalse(Tenant::find('alpha')->is_active);

        $this->postJson('/api/central/tenants/alpha/reactivate')->assertOk()->assertJsonPath('data.state', 'active');
        $this->assertTrue(Tenant::find('alpha')->is_active);

        $this->assertDatabaseHas('central_audit_logs', ['action' => 'school.suspend', 'tenant_id' => 'alpha', 'super_admin_id' => $admin->id]);
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'school.reactivate', 'tenant_id' => 'alpha', 'super_admin_id' => $admin->id]);

        $audit = $this->getJson('/api/central/tenants/alpha/audit')->assertOk()->json('data');
        $this->assertSame(['school.reactivate', 'school.suspend'], array_column($audit, 'action'));
        $this->assertSame('Owner', $audit[0]['actor']);
    }

    public function test_reactivating_a_closed_school_clears_the_window_and_closed_at(): void
    {
        $this->school('alpha');
        Tenant::find('alpha')->update(['is_active' => false, 'closed_at' => now()->subDay(), 'exit_window_ends_at' => now()->subHour()]);
        $this->owner();

        $this->assertSame('closed', $this->listed()[0]['state']);

        $this->postJson('/api/central/tenants/alpha/reactivate')->assertOk()->assertJsonPath('data.state', 'active');

        $tenant = Tenant::find('alpha');
        $this->assertNull($tenant->closed_at);
        $this->assertNull($tenant->exit_window_ends_at);
    }

    public function test_extend_sets_the_new_end_date(): void
    {
        $this->school('alpha', ['subscription_ends_at' => '2020-01-01']);
        $this->owner();

        $this->assertSame('expired', $this->listed()[0]['state']);

        $this->postJson('/api/central/tenants/alpha/extend', ['subscription_ends_at' => '2099-01-31'])
            ->assertOk()->assertJsonPath('data.state', 'active');

        $this->assertSame('2099-01-31', Tenant::find('alpha')->subscription_ends_at->toDateString());
        $this->assertDatabaseHas('central_audit_logs', ['action' => 'school.extend', 'tenant_id' => 'alpha']);
        $this->assertSame('2099-01-31', json_decode(DB::table('central_audit_logs')->where('action', 'school.extend')->value('meta'), true)['to']);

        $this->postJson('/api/central/tenants/alpha/extend', [])->assertStatus(422);
    }

    public function test_the_counts_are_cached(): void
    {
        $school = $this->school('alpha');
        $this->owner();
        Cache::forget('school.counts.alpha');

        $this->assertSame(0, $this->listed()[0]['students_count']);

        // A number change that is not an add or a remove of a student (written straight to the table) is not seen until the cache expires.
        $school->run(fn () => DB::table('students')->insert(['student_number' => 'S-1', 'name_ar' => 'طالب', 'gender' => 'male', 'created_at' => now(), 'updated_at' => now()]));
        $this->fresh();
        $this->assertSame(0, $this->listed()[0]['students_count'], 'served from the cache');
        $this->assertTrue(Cache::has('school.counts.alpha'));

        Cache::forget('school.counts.alpha');
        $this->assertSame(1, $this->listed()[0]['students_count']);
    }

    public function test_the_students_count_follows_the_school_at_once_when_it_adds_or_deletes_a_student(): void
    {
        $school = $this->school('alpha');
        $this->owner();
        $this->assertSame(0, $this->listed()[0]['students_count']);
        $this->assertTrue(Cache::has('school.counts.alpha'), 'the list caches the numbers');

        $student = $school->run(fn () => Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'gender' => 'male']));
        $this->fresh();
        $this->assertFalse(Cache::has('school.counts.alpha'), 'adding a student drops the cached numbers');
        $this->assertSame(1, $this->listed()[0]['students_count']);

        $school->run(fn () => $student->fresh()->delete());
        $this->fresh();
        $this->assertSame(0, $this->listed()[0]['students_count'], 'deleting a student shows at once, not after the cache expires');
    }

    public function test_the_staff_count_follows_the_school_at_once(): void
    {
        $school = $this->school('alpha');
        $this->owner();
        $before = $this->listed()[0]['staff_count'];

        $school->run(fn () => User::factory()->role(Role::Teacher)->create());
        $this->fresh();

        $this->assertSame($before + 1, $this->listed()[0]['staff_count']);
    }

    public function test_last_backup_at_is_the_newest_backup_or_null(): void
    {
        Storage::fake(config('backup.disk'));
        $this->school('alpha');
        $this->owner();

        $this->assertNull($this->listed()[0]['last_backup_at']);

        Storage::disk(config('backup.disk'))->put('alpha/2026-11-01_020000.zip', 'x');
        Storage::disk(config('backup.disk'))->put('alpha/2026-11-10_030405.zip', 'x');
        Storage::disk(config('backup.disk'))->put('alpha/2026-11-05_020000_pre-restore.zip', 'x');

        $this->assertStringStartsWith('2026-11-10T03:04:05', $this->listed()[0]['last_backup_at']);
    }
}
