<?php

namespace Tests\Feature\Central;

use App\Central\Models\Plan;
use App\Central\Models\SuperAdmin;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The pricing plans: the Super Admin decides all of them, the public website reads the active ones. */
class PlansApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function migrateFreshUsing(): array
    {
        return ['--drop-views' => $this->shouldDropViews(), '--drop-types' => $this->shouldDropTypes(), '--path' => 'database/migrations/central'];
    }

    private function owner(): void
    {
        $admin = SuperAdmin::create(['name' => 'Owner', 'email' => 'owner@madrasati.test', 'password' => 'a-very-long-password']);
        $this->withToken($admin->createToken('test')->plainTextToken);
    }

    private function body(array $extra = []): array
    {
        return $extra + [
            'name_ar' => 'الأساسية', 'name_en' => 'Basic', 'price_monthly' => 50, 'price_yearly' => 40, 'max_students' => 200,
            'description_ar' => 'لمدرسة صغيرة', 'description_en' => 'Small school',
            'features_ar' => ['الحضور', 'العلامات'], 'features_en' => ['Attendance', 'Grades'],
        ];
    }

    public function test_only_the_super_admin_can_manage_plans(): void
    {
        $this->getJson('/api/central/admin/plans')->assertUnauthorized();
        $this->postJson('/api/central/admin/plans', $this->body())->assertUnauthorized();
        $this->assertSame(0, Plan::count());
    }

    public function test_the_super_admin_creates_updates_and_deletes_a_plan(): void
    {
        $this->owner();

        $id = $this->postJson('/api/central/admin/plans', $this->body())->assertCreated()->assertJsonPath('data.currency', 'JOD')->json('data.id');
        $this->putJson("/api/central/admin/plans/$id", $this->body(['price_monthly' => 60, 'is_active' => false]))->assertOk()->assertJsonPath('data.price_monthly', 60)->assertJsonPath('data.is_active', false);
        $this->getJson('/api/central/admin/plans')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/central/admin/plans/$id")->assertNoContent();
        $this->assertSame(0, Plan::count());
        $this->assertSame(['plan.created', 'plan.updated', 'plan.deleted'], DB::table('central_audit_logs')->orderBy('id')->pluck('action')->all());
    }

    public function test_a_plan_must_be_complete_and_sensible(): void
    {
        $this->owner();

        foreach ([['price_monthly' => -1], ['features_en' => []], ['features_ar' => ['']], ['name_en' => ''], ['currency' => 'JODX'], ['max_students' => 0], ['price_yearly' => 'abc']] as $bad) {
            $this->postJson('/api/central/admin/plans', $this->body($bad))->assertStatus(422);
        }
        $this->assertSame(0, Plan::count());
    }

    public function test_only_one_plan_is_popular(): void
    {
        $this->owner();
        $a = $this->postJson('/api/central/admin/plans', $this->body(['is_popular' => true]))->json('data.id');
        $b = $this->postJson('/api/central/admin/plans', $this->body(['name_en' => 'Standard', 'is_popular' => true]))->json('data.id');

        $this->assertFalse(Plan::find($a)->is_popular);
        $this->assertTrue(Plan::find($b)->is_popular);
    }

    public function test_the_website_sees_only_active_plans_in_the_owners_order_without_signing_in(): void
    {
        Plan::create($this->body(['name_en' => 'Second', 'sort_order' => 2]));
        Plan::create($this->body(['name_en' => 'Hidden', 'is_active' => false]));
        Plan::create($this->body(['name_en' => 'First', 'sort_order' => 1]));

        $this->getJson('/api/central/plans')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name_en', 'First')->assertJsonPath('data.1.name_en', 'Second');
    }
}
