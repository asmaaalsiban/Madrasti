<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Academic\Models\Subject;
use App\Modules\Audit\Models\AuditLog;
use App\Shared\Enums\Role;
use Tests\TenantTestCase;

class AuditableTest extends TenantTestCase
{
    public function test_create_update_delete_write_audit_logs(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin);

        $subject = Subject::factory()->create(['code' => 'MTH', 'name_en' => 'Math']);
        $subject->update(['name_en' => 'Maths']);
        $subject->delete();

        $logs = AuditLog::where('auditable_type', Subject::class)->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame($admin->id, $logs[0]->user_id);
        $this->assertSame('MTH', $logs[0]->new_values['code']);
        $this->assertSame('Math', $logs[1]->old_values['name_en']);
        $this->assertSame('Maths', $logs[1]->new_values['name_en']);
        $this->assertSame($subject->id, $logs[2]->auditable_id);
        $this->assertSame(Subject::class, $logs[2]->auditable_type);
    }

    public function test_user_is_null_when_unauthenticated(): void
    {
        Subject::factory()->create();

        $this->assertNull(AuditLog::where('auditable_type', Subject::class)->first()->user_id);
    }
}
