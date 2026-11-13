<?php

namespace Tests\Feature\Console;

use App\Central\Models\Tenant;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Modules\Attendance\Jobs\SendAbsenceNotice;
use App\Modules\Attendance\Models\AbsenceNotification;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Discipline\Services\WarningRules;
use App\Modules\Finance\Services\FeeBilling;
use App\Modules\HR\Services\PayrollService;
use App\Modules\Scheduling\Services\DutyScheduler;
use App\Shared\Enums\Role;
use App\Shared\Messaging\MessageSender;
use Mockery\MockInterface;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Tests\TenantTestCase;

/**
 * Runs the daily commands and the notice job as if a school were initialised (the tenant is bound in the container,
 * the test database stays the single tenant schema).
 */
class BlockedSchoolSkipsTest extends TenantTestCase
{
    private function actAsSchool(bool $active): void
    {
        $this->app->instance(TenantContract::class, new Tenant(['id' => 'demo', 'is_active' => $active]));
    }

    public function test_finance_remind_sends_nothing_for_a_blocked_school(): void
    {
        $this->actAsSchool(false);
        $this->mock(FeeBilling::class, fn (MockInterface $m) => $m->shouldNotReceive('refreshOverdue'));
        $this->mock(Notifier::class, fn (MockInterface $m) => $m->shouldNotReceive('toUsers'));

        $this->artisan('finance:remind')->assertExitCode(0);
    }

    public function test_contracts_remind_sends_nothing_for_a_blocked_school(): void
    {
        $this->actAsSchool(false);
        $this->mock(PayrollService::class, fn (MockInterface $m) => $m->shouldNotReceive('remindExpiring'));

        $this->artisan('contracts:remind')->assertExitCode(0);
    }

    public function test_duties_remind_sends_nothing_for_a_blocked_school(): void
    {
        $this->actAsSchool(false);
        $this->mock(DutyScheduler::class, fn (MockInterface $m) => $m->shouldNotReceive('remind'));

        $this->artisan('duties:remind')->assertExitCode(0);
    }

    public function test_tasks_remind_sends_nothing_for_a_blocked_school(): void
    {
        $this->actAsSchool(false);
        $this->mock(Notifier::class, fn (MockInterface $m) => $m->shouldNotReceive('toUsers'));

        $this->artisan('tasks:remind')->assertExitCode(0)->doesntExpectOutputToContain('reminder(s) sent');
    }

    public function test_warnings_evaluate_does_nothing_for_a_blocked_school(): void
    {
        $this->actAsSchool(false);
        $this->mock(WarningRules::class, fn (MockInterface $m) => $m->shouldNotReceive('evaluate'));

        $this->artisan('warnings:evaluate')->assertExitCode(0);
    }

    public function test_the_absence_notice_job_does_nothing_for_a_blocked_school(): void
    {
        $attendance = $this->absentStudentWithGuardian();
        $sent = 0;
        $this->app->instance(MessageSender::class, new class($sent) implements MessageSender
        {
            public function __construct(private int &$sent) {}

            public function send(string $phone, string $text): void
            {
                $this->sent++;
            }
        });

        $this->actAsSchool(false);
        (new SendAbsenceNotice($attendance->id))->handle(app(MessageSender::class));

        $this->assertSame(0, $sent);
        $this->assertSame(0, AbsenceNotification::count());
    }

    public function test_an_active_school_still_gets_its_reminders(): void
    {
        $this->actAsSchool(true);
        $this->mock(FeeBilling::class, fn (MockInterface $m) => $m->shouldReceive('refreshOverdue')->once());
        $this->mock(PayrollService::class, fn (MockInterface $m) => $m->shouldReceive('remindExpiring')->once()->andReturn(0));
        $this->mock(DutyScheduler::class, fn (MockInterface $m) => $m->shouldReceive('remind')->once()->andReturn(0));
        $this->mock(WarningRules::class, fn (MockInterface $m) => $m->shouldReceive('evaluate')->once()->andReturn(['created' => 0, 'pending' => 0]));

        $this->artisan('finance:remind')->assertExitCode(0);
        $this->artisan('contracts:remind')->assertExitCode(0);
        $this->artisan('duties:remind')->assertExitCode(0);
        $this->artisan('tasks:remind')->expectsOutputToContain('reminder(s) sent')->assertExitCode(0);
        $this->artisan('warnings:evaluate')->assertExitCode(0);
    }

    public function test_the_absence_notice_job_still_notifies_for_an_active_school(): void
    {
        $attendance = $this->absentStudentWithGuardian();
        $sent = 0;
        $this->app->instance(MessageSender::class, new class($sent) implements MessageSender
        {
            public function __construct(private int &$sent) {}

            public function send(string $phone, string $text): void
            {
                $this->sent++;
            }
        });

        $this->actAsSchool(true);
        (new SendAbsenceNotice($attendance->id))->handle(app(MessageSender::class));

        $this->assertSame(1, $sent);
    }

    private function absentStudentWithGuardian(): StudentAttendance
    {
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $teacher = User::factory()->role(Role::Teacher)->create();
        $room = Classroom::factory()->create(['academic_year_id' => $year->id, 'homeroom_teacher_id' => $teacher->id]);
        $student = Student::create(['student_number' => 'S-1', 'name_ar' => 'علي', 'name_en' => 'Ali', 'gender' => 'male']);
        $parent = User::factory()->role(Role::Guardian)->create(['phone' => '0599123456']);
        $student->guardians()->attach($parent->id, ['is_primary' => true]);

        return StudentAttendance::create(['student_id' => $student->id, 'classroom_id' => $room->id, 'date' => '2026-10-19', 'status' => 'absent', 'marked_by' => $teacher->id]);
    }
}
