<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Procurement\Models\ProcurementRequest;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ProcurementTest extends AcademicTestCase
{
    private User $principal;

    private User $accountant;

    private User $sara;

    private User $omar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->principal = User::factory()->staff(StaffType::Principal)->create(['name' => 'Principal']);
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create(['name' => 'Accountant']);
        $this->sara = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
        $this->omar = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Omar']);
    }

    private function body(array $extra = []): array
    {
        return ['title' => 'Whiteboard markers', 'quantity' => 20, 'category' => 'stationery', 'priority' => 'normal', 'estimated_cost' => 35, ...$extra];
    }

    private function ask(User $by, array $extra = []): int
    {
        Sanctum::actingAs($by);

        return $this->postJson('/api/procurement', $this->body($extra))->assertCreated()->json('data.id');
    }

    private function notice(User $user, string $kind): int
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->where('data', 'like', "%$kind%")->count();
    }

    public function test_a_request_goes_from_pending_to_approved_to_purchased_to_received_and_everyone_is_told(): void
    {
        $id = $this->ask($this->sara);
        $this->assertSame(1, $this->notice($this->principal, 'procurement_new'));

        Sanctum::actingAs($this->principal);
        $this->postJson("/api/procurement/$id/approve", ['note' => 'Go ahead'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.reviewed_by', 'Principal');
        $this->assertSame(1, $this->notice($this->sara, 'procurement_approved'));
        $this->assertSame(1, $this->notice($this->accountant, 'procurement_to_buy'));

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/procurement/$id/purchase", ['supplier' => 'Al Noor Library', 'actual_cost' => 32.5, 'purchase_ref' => 'INV-77'])->assertOk()->assertJsonPath('data.status', 'purchased')->assertJsonPath('data.actual_cost', '32.50')->assertJsonPath('data.supplier', 'Al Noor Library');
        $this->assertSame(1, $this->notice($this->sara, 'procurement_purchased'));

        Sanctum::actingAs($this->sara);
        $this->postJson("/api/procurement/$id/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertTrue(AuditLog::where('auditable_type', ProcurementRequest::class)->where('action', 'updated')->exists());
    }

    public function test_the_principal_can_refuse_with_a_reason_and_the_requester_can_remove_a_refused_request(): void
    {
        $id = $this->ask($this->sara);
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/procurement/$id/reject")->assertUnprocessable()->assertJsonValidationErrors('note'); // a reason is needed
        $this->postJson("/api/procurement/$id/reject", ['note' => 'Too expensive'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.review_note', 'Too expensive');
        $this->assertSame(1, $this->notice($this->sara, 'procurement_rejected'));
        $this->postJson("/api/procurement/$id/approve")->assertUnprocessable(); // already decided
        Sanctum::actingAs($this->sara);
        $this->deleteJson("/api/procurement/$id")->assertNoContent();
        $this->assertNull(ProcurementRequest::find($id));
    }

    public function test_each_step_belongs_to_its_role_and_cannot_be_skipped(): void
    {
        $id = $this->ask($this->sara);
        Sanctum::actingAs($this->sara);
        $this->postJson("/api/procurement/$id/approve")->assertForbidden(); // the requester cannot approve their own
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/procurement/$id/approve")->assertForbidden();
        $this->postJson("/api/procurement/$id/purchase", ['supplier' => 'X', 'actual_cost' => 5])->assertUnprocessable(); // not approved yet
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/procurement/$id/receive")->assertUnprocessable(); // not bought yet
        $this->postJson("/api/procurement/$id/approve")->assertOk();
        $this->postJson("/api/procurement/$id/purchase", ['supplier' => 'X', 'actual_cost' => 5])->assertForbidden(); // the accountant records purchases
        Sanctum::actingAs($this->omar);
        $this->postJson("/api/procurement/$id/receive")->assertForbidden(); // not his request
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/procurement/$id/purchase", ['supplier' => '', 'actual_cost' => 5])->assertUnprocessable();
        $this->postJson("/api/procurement/$id/purchase", ['supplier' => 'X', 'actual_cost' => 0])->assertUnprocessable();
        $this->postJson("/api/procurement/$id/purchase", ['supplier' => 'X', 'actual_cost' => 5])->assertOk();
        $this->postJson("/api/procurement/$id/purchase", ['supplier' => 'Y', 'actual_cost' => 6])->assertUnprocessable(); // once
    }

    public function test_staff_see_only_their_own_requests_the_principal_and_accountant_see_all_with_totals(): void
    {
        $a = $this->ask($this->sara, ['title' => 'A', 'estimated_cost' => 100, 'priority' => 'low']);
        $this->ask($this->omar, ['title' => 'B', 'estimated_cost' => 50, 'priority' => 'urgent']);
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/procurement/$a/approve")->assertOk();
        Sanctum::actingAs($this->sara);
        $mine = $this->getJson('/api/procurement')->assertOk()->json();
        $this->assertSame(['A'], collect($mine['data'])->pluck('title')->all());
        $this->assertFalse($mine['can_review']);
        Sanctum::actingAs($this->omar);
        $this->assertSame(['B'], collect($this->getJson('/api/procurement')->json('data'))->pluck('title')->all());

        Sanctum::actingAs($this->principal);
        $all = $this->getJson('/api/procurement')->assertOk()->json();
        $this->assertSame(['B', 'A'], collect($all['data'])->pluck('title')->all()); // urgent first
        $this->assertTrue($all['can_review']);
        $this->assertSame(1, $all['summary']['pending']);
        $this->assertSame(1, $all['summary']['approved']);
        $this->assertSame('100.00', $all['summary']['to_spend']);
        Sanctum::actingAs($this->accountant);
        $this->assertTrue($this->getJson('/api/procurement')->json('can_buy'));
        $this->assertCount(2, $this->getJson('/api/procurement')->json('data'));
        $this->postJson("/api/procurement/$a/purchase", ['supplier' => 'S', 'actual_cost' => 90])->assertOk();
        $this->assertSame('90.00', $this->getJson('/api/procurement')->json('summary.spent'));
    }

    public function test_the_requester_edits_only_while_pending_and_requests_are_validated(): void
    {
        $id = $this->ask($this->sara);
        Sanctum::actingAs($this->sara);
        $this->putJson("/api/procurement/$id", $this->body(['title' => 'Markers (blue)', 'quantity' => 30]))->assertOk()->assertJsonPath('data.title', 'Markers (blue)');
        $this->putJson("/api/procurement/$id", $this->body(['quantity' => 0]))->assertUnprocessable();
        $this->postJson('/api/procurement', $this->body(['category' => 'cars']))->assertUnprocessable();
        $this->postJson('/api/procurement', $this->body(['priority' => 'whenever']))->assertUnprocessable();
        $this->postJson('/api/procurement', ['quantity' => 1, 'category' => 'other', 'priority' => 'low'])->assertUnprocessable(); // a title is needed
        Sanctum::actingAs($this->omar);
        $this->putJson("/api/procurement/$id", $this->body())->assertForbidden();
        $this->deleteJson("/api/procurement/$id")->assertForbidden();
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/procurement/$id/approve")->assertOk();
        Sanctum::actingAs($this->sara);
        $this->putJson("/api/procurement/$id", $this->body())->assertForbidden(); // decided
        $this->deleteJson("/api/procurement/$id")->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Guardian]));
        $this->getJson('/api/procurement')->assertForbidden();
    }
}
