<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

/**
 * School routes find the school from the X-School header (one public URL for every school).
 */
class SchoolHeaderTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->json('data')->nullable();
        });
    }

    public function test_school_routes_return_404_without_the_header(): void
    {
        $this->getJson('/api/school')
            ->assertNotFound()
            ->assertJsonPath('message', __('tenancy.school_not_found'));
    }

    public function test_school_routes_return_404_for_an_unknown_school_code(): void
    {
        $this->getJson('/api/school', ['X-School' => 'nope'])
            ->assertNotFound()
            ->assertJsonPath('message', __('tenancy.school_not_found'));
    }

    public function test_the_school_query_parameter_is_not_accepted(): void
    {
        DB::table('tenants')->insert(['id' => 'alnoor']);

        $this->getJson('/api/school?tenant=alnoor')->assertNotFound();
    }

    public function test_the_domain_is_no_longer_what_identifies_a_school(): void
    {
        $this->getJson('http://alnoor.localhost/api/school')->assertNotFound();
    }
}
