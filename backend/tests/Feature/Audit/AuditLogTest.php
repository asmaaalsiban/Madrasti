<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Audit\Models\AuditLog;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class AuditLogTest extends AcademicTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->actAsAdmin();
        $student = Student::create(['student_number' => 'A-1', 'name_ar' => 'علي', 'name_en' => 'Ali']);
        $student->update(['name_en' => 'Ali Hasan']);
        $student->delete();
    }

    public function test_it_lists_what_was_created_updated_and_deleted_newest_first_with_the_changes(): void
    {
        $rows = collect($this->getJson('/api/audit-logs')->assertOk()->json('data'));
        $this->assertSame(['deleted', 'updated', 'created'], $rows->where('type', 'Student')->pluck('action')->values()->all());
        $update = $rows->firstWhere('action', 'updated');
        $this->assertSame($this->admin->id, $update['user']['id']);
        $this->assertSame([['field' => 'name_en', 'old' => 'Ali', 'new' => 'Ali Hasan']], collect($update['changes'])->reject(fn ($c) => $c['field'] === 'deleted_at')->values()->all());
        $created = $rows->firstWhere('action', 'created');
        $this->assertNull(collect($created['changes'])->firstWhere('field', 'id')); // noise is left out
        $this->assertSame('Ali', collect($created['changes'])->firstWhere('field', 'name_en')['new']);
    }

    public function test_it_filters_by_action_kind_person_date_and_record(): void
    {
        $this->getJson('/api/audit-logs?action=deleted')->assertOk()->assertJsonCount(1, 'data');
        $this->assertGreaterThan(0, count($this->getJson('/api/audit-logs?type=Student')->json('data')));
        $this->getJson('/api/audit-logs?type=Payment')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/audit-logs?user_id='.($this->admin->id + 99))->assertJsonCount(0, 'data');
        $this->getJson('/api/audit-logs?from=2999-01-01')->assertJsonCount(0, 'data');
        $this->assertGreaterThanOrEqual(3, count($this->getJson('/api/audit-logs?from='.now()->toDateString().'&to='.now()->toDateString())->json('data')));
        $this->assertGreaterThanOrEqual(3, count($this->getJson('/api/audit-logs?record_id='.Student::withTrashed()->value('id').'&type=Student')->json('data')));
        $this->getJson('/api/audit-logs?action=bogus')->assertUnprocessable();
    }

    public function test_the_filters_list_the_kinds_and_people_and_the_log_exports_to_excel(): void
    {
        $f = $this->getJson('/api/audit-logs/filters')->assertOk()->json();
        $this->assertContains('Student', $f['types']);
        $this->assertSame($this->admin->id, $f['users'][0]['id']);
        $this->get('/api/audit-logs/export?type=Student')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_only_the_school_management_can_read_it(): void
    {
        foreach ([User::factory()->staff(StaffType::Accountant)->create(), User::factory()->staff(StaffType::Teacher)->create(), User::factory()->create(['role' => Role::Guardian])] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/audit-logs')->assertForbidden();
            $this->getJson('/api/audit-logs/filters')->assertForbidden();
            $this->get('/api/audit-logs/export')->assertForbidden();
        }
        $this->assertGreaterThan(0, AuditLog::count());
    }

    public function test_a_password_hash_is_never_shown(): void
    {
        $user = User::factory()->create();
        $user->update(['password' => 'new-secret-12345']);
        $rows = collect($this->getJson('/api/audit-logs?type=User')->json('data'));
        $change = collect($rows->firstWhere('action', 'updated')['changes'])->firstWhere('field', 'password');
        $this->assertSame('[hidden]', $change['new']);
    }
}
