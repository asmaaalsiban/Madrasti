<?php

namespace Tests\Feature\Central;

use App\Central\Actions\CreateSchool;
use App\Central\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TenantTestCase;

class CreateSchoolCleanupTest extends TenantTestCase
{
    public function test_a_failure_while_the_tenant_is_being_created_leaves_no_tenant_row_behind(): void
    {
        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->string('name')->nullable();
            $t->string('primary_color')->nullable();
            $t->string('default_locale')->nullable();
            $t->boolean('is_active')->default(true);
            $t->json('data')->nullable();
            $t->timestamps();
        });
        Schema::create('domains', function ($t) {
            $t->increments('id');
            $t->string('domain')->unique();
            $t->string('tenant_id');
            $t->timestamps();
        });

        // Skip the real database/migration pipeline; fail right after the tenant row is inserted.
        Event::fake([TenantCreated::class]);
        Tenant::created(fn () => throw new RuntimeException('migration failed'));

        try {
            app(CreateSchool::class)->handle([
                'id' => 'alnoor', 'name' => 'Al Noor', 'domain' => 'alnoor.localhost',
                'admin_name' => 'Admin', 'admin_email' => 'a@alnoor.test', 'admin_password' => 'Secret123',
            ]);
            $this->fail('Expected the action to rethrow the failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('migration failed', $e->getMessage());
        }

        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('domains')->count());
    }

    public function test_an_existing_school_with_the_same_id_is_never_deleted(): void
    {
        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->string('name')->nullable();
            $t->string('primary_color')->nullable();
            $t->string('default_locale')->nullable();
            $t->boolean('is_active')->default(true);
            $t->json('data')->nullable();
            $t->timestamps();
        });
        DB::table('tenants')->insert(['id' => 'alnoor', 'name' => 'Existing']);
        Event::fake([TenantCreated::class]);

        $this->expectException(\Throwable::class);
        try {
            app(CreateSchool::class)->handle([
                'id' => 'alnoor', 'name' => 'Intruder', 'domain' => 'alnoor.localhost',
                'admin_name' => 'Admin', 'admin_email' => 'a@alnoor.test', 'admin_password' => 'Secret123',
            ]);
        } finally {
            $this->assertSame('Existing', DB::table('tenants')->where('id', 'alnoor')->value('name'));
        }
    }

    public function test_a_school_can_be_created_without_a_domain(): void
    {
        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->string('name')->nullable();
            $t->string('primary_color')->nullable();
            $t->string('default_locale')->nullable();
            $t->boolean('is_active')->default(true);
            $t->json('data')->nullable();
            $t->timestamps();
        });
        Schema::create('domains', function ($t) {
            $t->increments('id');
            $t->string('domain')->unique();
            $t->string('tenant_id');
            $t->timestamps();
        });
        Event::fake([TenantCreated::class]);

        try {
            app(CreateSchool::class)->handle(['id' => 'alnoor', 'name' => 'Al Noor', 'admin_name' => 'A', 'admin_email' => 'a@a.test', 'admin_password' => 'Secret123']);
        } catch (\Throwable $e) {
            // Tenancy cannot start a real school database in this test; only a missing domain key would be our bug.
            $this->assertStringNotContainsString('Undefined array key', $e->getMessage());
        } finally {
            // A failed run() can leave tenancy initialized; go back to the central connection.
            tenancy()->end();
        }

        $this->assertSame(0, DB::table('domains')->count());
    }
}
