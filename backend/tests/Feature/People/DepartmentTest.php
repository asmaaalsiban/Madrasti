<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\AttendanceStatus;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class DepartmentTest extends AcademicTestCase
{
    private Subject $math;

    private User $head;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 08:00:00');
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->math = Subject::factory()->create();
        $this->head = User::factory()->staff(StaffType::HeadOfDepartment, ['subject_id' => $this->math->id])->create();
    }

    private function teacher(Subject $subject, array $attributes = [], StaffType $type = StaffType::Teacher): User
    {
        return User::factory()->staff($type, ['subject_id' => $subject->id])->create($attributes);
    }

    public function test_the_head_sees_the_teachers_of_their_own_department_only(): void
    {
        $mine = $this->teacher($this->math, ['name' => 'Math One']);
        $this->teacher($this->math, ['name' => 'Math Gone'])->update(['is_active' => false]);
        $this->teacher(Subject::factory()->create(), ['name' => 'Science One']);
        User::factory()->staff(StaffType::Nurse)->create(['name' => 'Nurse']);
        Sanctum::actingAs($this->head);

        $this->getJson('/api/department')->assertOk()
            ->assertJsonPath('data.subject.id', $this->math->id)
            ->assertJsonCount(1, 'data.teachers')
            ->assertJsonPath('data.teachers.0.id', $mine->id)
            ->assertJsonPath('data.teachers.0.name', 'Math One');
    }

    public function test_each_teacher_shows_their_classrooms_homeroom_and_attendance_activity(): void
    {
        $teacher = $this->teacher($this->math);
        $homeroom = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $this->year->id, 'homeroom_teacher_id' => $teacher->id]);
        $other = Classroom::factory()->create(['name' => '5-B', 'academic_year_id' => $this->year->id]);
        $old = Classroom::factory()->create(['name' => '4-A']); // another academic year
        foreach ([$homeroom, $other, $old] as $room) {
            DB::table('classroom_subject_teacher')->insert(['classroom_id' => $room->id, 'subject_id' => $this->math->id, 'teacher_id' => $teacher->id, 'weekly_periods' => 3, 'created_at' => now(), 'updated_at' => now()]);
        }
        $student = Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'gender' => 'male']);
        foreach (['2026-10-20', '2026-10-19', '2026-09-01'] as $day) {
            StudentAttendance::create(['student_id' => $student->id, 'classroom_id' => $homeroom->id, 'date' => $day, 'status' => AttendanceStatus::Present, 'marked_by' => $teacher->id]);
        }
        Sanctum::actingAs($this->head);

        $row = $this->getJson('/api/department')->assertOk()->json('data.teachers.0');

        $this->assertSame('5-A', $row['homeroom']);
        $this->assertEqualsCanonicalizing(['5-A', '5-B'], $row['classrooms']);
        $this->assertTrue($row['attendance']['recorded_today']);
        $this->assertSame(2, $row['attendance']['days_last_30']);
    }

    public function test_a_teacher_without_a_homeroom_has_no_attendance_figures(): void
    {
        $this->teacher($this->math);
        Sanctum::actingAs($this->head);

        $this->getJson('/api/department')->assertOk()
            ->assertJsonPath('data.teachers.0.homeroom', null)
            ->assertJsonPath('data.teachers.0.attendance', null);
    }

    public function test_only_a_head_of_department_may_open_the_page(): void
    {
        foreach ([StaffType::Teacher, StaffType::SubjectCoordinator, StaffType::Nurse] as $type) {
            Sanctum::actingAs(User::factory()->staff($type, ['subject_id' => $type->needsSubject() ? $this->math->id : null])->create());
            $this->getJson('/api/department')->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson('/api/department')->assertForbidden();
    }

    public function test_a_head_without_a_subject_gets_an_empty_department(): void
    {
        $head = User::factory()->staff(StaffType::HeadOfDepartment)->create();
        Sanctum::actingAs($head);

        $this->getJson('/api/department')->assertOk()->assertJsonPath('data.subject', null)->assertJsonCount(0, 'data.teachers');
    }

    public function test_the_signed_in_user_tells_the_staff_type_so_the_app_can_show_the_page(): void
    {
        Sanctum::actingAs($this->head);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.staff_type', 'head_of_department');
    }
}
