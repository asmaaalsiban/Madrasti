<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Attendance\Models\AbsenceNotification;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use App\Shared\Messaging\MessageSender;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class AttendanceTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $room;

    /** @var list<Student> */
    private array $students;

    /** @var list<array{0: string, 1: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 08:00:00');

        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->teacher = User::factory()->staff(StaffType::Teacher)->create();
        $this->room = Classroom::factory()->create(['academic_year_id' => $year->id, 'homeroom_teacher_id' => $this->teacher->id]);
        foreach ([1, 2, 3] as $n) {
            $student = Student::create(['student_number' => "S-$n", 'name_ar' => "طالب $n", 'name_en' => "Student $n", 'gender' => 'male']);
            Enrollment::create(['student_id' => $student->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);
            $this->students[] = $student;
        }

        $this->app->instance(MessageSender::class, new class($this->sent) implements MessageSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $text): void
            {
                $this->sent[] = [$phone, $text];
            }
        });
    }

    private function guardianOf(Student $student, ?string $phone = ''): User
    {
        $phone = $phone === '' ? '05991'.random_int(10000, 99999) : $phone;
        $parent = User::factory()->role(Role::Guardian)->create(['phone' => $phone]);
        $student->guardians()->attach($parent->id, ['is_primary' => true]);

        return $parent;
    }

    private function save(array $statuses, ?string $date = null, ?Classroom $room = null)
    {
        return $this->putJson('/api/attendance', [
            'classroom_id' => ($room ?? $this->room)->id,
            'date' => $date ?? '2026-10-20',
            'records' => collect($this->students)->map(fn ($s, $i) => ['student_id' => $s->id, 'status' => $statuses[$i] ?? 'present'])->all(),
        ]);
    }

    public function test_the_homeroom_teacher_lists_only_their_own_classrooms(): void
    {
        Classroom::factory()->create(['academic_year_id' => $this->room->academic_year_id]);
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/attendance/classrooms')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->room->id);
    }

    public function test_admin_lists_every_classroom_of_the_current_year(): void
    {
        Classroom::factory()->create(['academic_year_id' => $this->room->academic_year_id]);
        Classroom::factory()->create(); // another (not current) year
        $this->actAsAdmin();

        $this->getJson('/api/attendance/classrooms')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_roster_has_every_student_and_no_status_before_the_day_is_recorded(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/attendance?classroom_id={$this->room->id}&date=2026-10-20")
            ->assertOk()
            ->assertJsonPath('data.recorded', false)
            ->assertJsonCount(3, 'data.students')
            ->assertJsonPath('data.students.0.status', null);
    }

    public function test_the_teacher_records_a_day_and_the_roster_shows_it_back(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->save(['present', 'absent', 'late'])->assertOk();

        $this->assertDatabaseHas('student_attendances', ['student_id' => $this->students[1]->id, 'date' => '2026-10-20', 'status' => 'absent', 'marked_by' => $this->teacher->id]);
        $this->getJson("/api/attendance?classroom_id={$this->room->id}&date=2026-10-20")
            ->assertJsonPath('data.recorded', true)
            ->assertJsonPath('data.students.1.status', 'absent')
            ->assertJsonPath('data.students.2.status', 'late');
    }

    public function test_saving_again_updates_the_day_instead_of_duplicating_it(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->save(['absent', 'present', 'present'])->assertOk();
        $this->save(['present', 'present', 'late'])->assertOk();

        $this->assertSame(3, StudentAttendance::count());
        $this->assertSame('present', StudentAttendance::where('student_id', $this->students[0]->id)->value('status')->value);
    }

    public function test_a_teacher_cannot_record_another_teachers_classroom(): void
    {
        $other = Classroom::factory()->create(['academic_year_id' => $this->room->academic_year_id]);
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/attendance?classroom_id={$other->id}&date=2026-10-20")->assertForbidden();
        $this->save([], '2026-10-20', $other)->assertForbidden();
    }

    public function test_admin_may_record_any_classroom(): void
    {
        $this->actAsAdmin();
        $this->save(['absent'])->assertOk();
    }

    public function test_parents_and_students_cannot_record_attendance(): void
    {
        foreach ([Role::Guardian, Role::Student] as $role) {
            Sanctum::actingAs(User::factory()->role($role)->create());
            $this->save([])->assertForbidden();
            $this->getJson('/api/attendance/classrooms')->assertForbidden();
        }
    }

    public function test_validation_rejects_the_future_unknown_statuses_foreign_students_and_dates_outside_the_year(): void
    {
        Sanctum::actingAs($this->teacher);
        $stranger = Student::create(['student_number' => 'X', 'name_ar' => 'غريب', 'gender' => 'male']);

        $this->save([], '2026-10-21')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->save([], '2026-08-01')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->save(['excused'])->assertUnprocessable();
        $this->putJson('/api/attendance', ['classroom_id' => $this->room->id, 'date' => '2026-10-20', 'records' => [['student_id' => $stranger->id, 'status' => 'absent']]])
            ->assertUnprocessable()->assertJsonValidationErrors('records.0.student_id');
        $this->putJson('/api/attendance', ['classroom_id' => 'abc', 'date' => '2026-10-20', 'records' => []])->assertUnprocessable();
        $this->assertSame(0, StudentAttendance::count());
    }

    public function test_an_absent_student_sends_one_message_to_the_parent_even_when_saved_twice(): void
    {
        $this->guardianOf($this->students[1], '0599000111');
        Sanctum::actingAs($this->teacher);

        $this->save(['present', 'absent'])->assertOk();
        $this->save(['present', 'absent', 'late'])->assertOk();

        $this->assertCount(1, $this->sent);
        $this->assertSame('0599000111', $this->sent[0][0]);
        $this->assertStringContainsString('Student 2', $this->sent[0][1]);
        $this->assertDatabaseHas('absence_notifications', ['status' => 'sent', 'phone' => '0599000111', 'channel' => 'log']);
    }

    public function test_late_and_present_students_send_nothing(): void
    {
        $this->guardianOf($this->students[0]);
        $this->guardianOf($this->students[1]);
        Sanctum::actingAs($this->teacher);

        $this->save(['present', 'late'])->assertOk();

        $this->assertSame([], $this->sent);
        $this->assertSame(0, AbsenceNotification::count());
    }

    public function test_a_parent_without_a_phone_is_skipped_and_a_failing_provider_does_not_break_the_save(): void
    {
        $this->guardianOf($this->students[0], null);
        $this->guardianOf($this->students[1], '0599000222');
        $this->app->instance(MessageSender::class, new class implements MessageSender
        {
            public function send(string $phone, string $text): void
            {
                throw new \RuntimeException('provider down');
            }
        });
        Sanctum::actingAs($this->teacher);

        $this->save(['absent', 'absent'])->assertOk();

        $this->assertDatabaseHas('absence_notifications', ['status' => 'skipped', 'phone' => null]);
        $this->assertDatabaseHas('absence_notifications', ['status' => 'failed', 'phone' => '0599000222', 'error' => 'provider down']);

        // The next save tries the failed one again (and now it works).
        $this->app->instance(MessageSender::class, new class($this->sent) implements MessageSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $text): void
            {
                $this->sent[] = [$phone, $text];
            }
        });
        $this->save(['absent', 'absent'])->assertOk();
        $this->assertCount(1, $this->sent);
        $this->assertDatabaseHas('absence_notifications', ['status' => 'sent', 'phone' => '0599000222']);
    }

    public function test_every_change_is_audited(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->save(['absent'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['auditable_type' => StudentAttendance::class, 'action' => 'created', 'user_id' => $this->teacher->id]);
    }

    public function test_the_report_counts_absences_and_lateness_per_student(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->save(['absent', 'late', 'present'], '2026-10-18');
        $this->save(['absent', 'present', 'present'], '2026-10-19');
        $this->save(['absent', 'late', 'present'], '2026-10-20');

        $this->getJson("/api/attendance/report?classroom_id={$this->room->id}")
            ->assertOk()
            ->assertJsonPath('data.0.student.id', $this->students[0]->id)
            ->assertJsonPath('data.0.absent', 3)
            ->assertJsonPath('data.0.late', 0)
            ->assertJsonPath('data.1.student.id', $this->students[1]->id)
            ->assertJsonPath('data.1.late', 2)
            ->assertJsonPath('data.2.absent', 0)
            ->assertJsonPath('data.0.classroom', $this->room->name);
    }

    public function test_the_report_can_be_limited_to_dates_and_is_scoped_to_the_teachers_classrooms(): void
    {
        $other = Classroom::factory()->create(['academic_year_id' => $this->room->academic_year_id]);
        $stranger = Student::create(['student_number' => 'X', 'name_ar' => 'غريب', 'gender' => 'male']);
        Enrollment::create(['student_id' => $stranger->id, 'classroom_id' => $other->id, 'academic_year_id' => $other->academic_year_id]);
        Sanctum::actingAs($this->teacher);
        $this->save(['absent'], '2026-10-01');
        $this->save(['absent'], '2026-10-20');

        $this->getJson('/api/attendance/report?from=2026-10-10&to=2026-10-31')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.absent', 1);
        $this->getJson("/api/attendance/report?classroom_id={$other->id}")->assertForbidden();

        $this->actAsAdmin();
        $this->getJson('/api/attendance/report')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_the_student_detail_lists_the_dates_of_absence(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->save(['absent'], '2026-10-18');
        $this->save(['late'], '2026-10-19');
        $this->save(['absent'], '2026-10-20');

        $this->getJson("/api/attendance/students/{$this->students[0]->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.absent', 2)
            ->assertJsonPath('data.summary.late', 1)
            ->assertJsonPath('data.summary.present', 0)
            ->assertJsonCount(3, 'data.records')
            ->assertJsonPath('data.records.0.date', '2026-10-20')
            ->assertJsonPath('data.records.0.status', 'absent');
        $this->getJson("/api/attendance/students/{$this->students[0]->id}?status=absent")->assertJsonCount(2, 'data.records');
    }

    public function test_a_parent_sees_only_their_own_childs_attendance(): void
    {
        $parent = $this->guardianOf($this->students[0]);
        Sanctum::actingAs($this->teacher);
        $this->save(['absent']);

        Sanctum::actingAs($parent);
        $this->getJson("/api/attendance/students/{$this->students[0]->id}")->assertOk()->assertJsonPath('data.summary.absent', 1);
        $this->getJson("/api/attendance/students/{$this->students[1]->id}")->assertForbidden();
        $this->getJson('/api/attendance/report')->assertForbidden();
    }

    public function test_a_student_sees_their_own_attendance_and_another_teacher_does_not(): void
    {
        $account = User::factory()->role(Role::Student)->create();
        $this->students[0]->update(['user_id' => $account->id]);

        Sanctum::actingAs($account);
        $this->getJson("/api/attendance/students/{$this->students[0]->id}")->assertOk();
        $this->getJson("/api/attendance/students/{$this->students[1]->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->getJson("/api/attendance/students/{$this->students[0]->id}")->assertForbidden();
    }
}
